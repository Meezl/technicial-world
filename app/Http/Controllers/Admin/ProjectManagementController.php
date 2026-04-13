<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TimeLog;
use App\Models\Comment;
use App\Models\ProjectFile;
use App\Models\ProjectActivity;
use App\Models\KanbanBoard;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class ProjectManagementController extends Controller
{
    // ==================== DASHBOARD ====================
    public function dashboard()
    {
        $stats = Cache::remember('project_stats', 3600, function () {
            return [
                'totalProjects' => Project::count(),
                'activeProjects' => Project::where('status', Project::STATUS_ACTIVE)->count(),
                'completedProjects' => Project::where('status', Project::STATUS_COMPLETED)->count(),
                'totalTasks' => Task::count(),
                'completedTasks' => Task::where('status', Task::STATUS_DONE)->count(),
                'overdueProjects' => Project::overdue()->count(),
                'totalHoursLogged' => round(TimeLog::sum('duration_minutes') / 60, 1),
            ];
        });

        // Project status distribution (for pie chart)
        $projectsByStatus = Project::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Team workload (for bar chart)
        $teamWorkload = User::whereHas('assignedTasks', function ($q) {
            $q->whereIn('status', [Task::STATUS_TODO, Task::STATUS_IN_PROGRESS]);
        })
            ->withCount('assignedTasks')
            ->orderBy('assigned_tasks_count', 'desc')
            ->limit(10)
            ->get();

        // Recent activities
        $recentActivities = ProjectActivity::with(['project', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        return Inertia::render('Admin/Projects/Dashboard', [
            'stats' => $stats,
            'projectsByStatus' => $projectsByStatus,
            'teamWorkload' => $teamWorkload,
            'recentActivities' => $recentActivities,
        ]);
    }

    // ==================== PROJECT CRUD ====================
    public function index()
    {
        $projects = Project::with(['creator', 'tasks', 'serviceRequest'])
            ->orderBy('created_at', 'desc')
            ->get();

        $users = User::select('id', 'name', 'email', 'role')->get();

        return Inertia::render('Admin/Projects/Index', [
            'projects' => $projects,
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:planning,active,on_hold,completed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'budget_amount' => 'nullable|numeric|min:0',
            'team_members' => 'nullable|array',
            'team_members.*' => 'exists:users,id',
            'tags' => 'nullable|array',
        ]);

        $project = Project::create([
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ?? Project::STATUS_PLANNING,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'budget_amount' => $request->budget_amount,
            'team_members' => $request->team_members,
            'tags' => $request->tags,
            'created_by' => auth()->id(),
        ]);

        // Create default Kanban board
        KanbanBoard::create([
            'project_id' => $project->id,
            'name' => 'Main Board',
            'columns' => KanbanBoard::defaultColumns(),
            'is_default' => true,
        ]);

        $project->logActivity(ProjectActivity::TYPE_PROJECT_CREATED, "Project '{$project->name}' created");

        // Send email notification to team members
        if (!empty($request->team_members)) {
            $users = User::whereIn('id', $request->team_members)->get();
            foreach ($users as $user) {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\ProjectAssignedMail($project, auth()->user()));
            }
        }

        return redirect()->route('admin.projects')->with('success', 'Project created successfully!');
    }

    public function show(Project $project)
    {
        $project->load([
            'creator',
            'serviceRequest',
            'tasks.assignments.user',
            'tasks.comments.user',
            'tasks.comments.reactions', // Load reactions
            'tasks.subtasks',
            'tasks.dependencies.dependsOnTask',
            'tasks.files.uploader',
            'files.uploader',
            'activities.user',
        ]);

        $users = User::select('id', 'name', 'email', 'role')->get();

        // Check if loading for navigation tree
        $allProjects = Project::select('id', 'name', 'parent_id', 'status')->orderBy('name')->get();

        return Inertia::render('Admin/Projects/Show', [
            'project' => $project,
            'users' => $users,
            'allProjects' => $allProjects,
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:planning,active,on_hold,completed,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'budget_amount' => 'nullable|numeric|min:0',
            'team_members' => 'nullable|array',
            'team_members.*' => 'exists:users,id',
            'tags' => 'nullable|array',
        ]);

        $oldTeamMembers = $project->team_members ?? [];

        $project->update($request->only([
            'name',
            'description',
            'status',
            'start_date',
            'end_date',
            'budget_amount',
            'team_members',
            'tags',
        ]));

        $project->logActivity(ProjectActivity::TYPE_PROJECT_UPDATED, "Project '{$project->name}' updated");

        $newTeamMembers = $request->team_members ?? [];
        $addedMembers = array_diff($newTeamMembers, $oldTeamMembers);

        if (!empty($addedMembers)) {
            $users = User::whereIn('id', $addedMembers)->get();
            foreach ($users as $user) {
                \Illuminate\Support\Facades\Mail::to($user->email)
                    ->send(new \App\Mail\ProjectAssignedMail($project, auth()->user()));
            }
        }

        return redirect()->back()->with('success', 'Project updated successfully!');
    }

    public function destroy(Project $project)
    {
        // Check if project has active tasks
        $activeTasks = $project->tasks()->whereNotIn('status', [Task::STATUS_DONE])->count();

        if ($activeTasks > 0) {
            return redirect()->route('admin.projects')->with('error', 'Cannot delete project with active tasks.');
        }

        $project->delete();

        return redirect()->route('admin.projects')->with('success', 'Project deleted successfully!');
    }

    // ==================== TASK MANAGEMENT ====================
    public function storeTask(Request $request, Project $project)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:todo,in_progress,review,done,blocked',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'parent_task_id' => 'nullable|exists:tasks,id',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'estimated_hours' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
        ]);

        $maxOrder = Task::where('project_id', $project->id)
            ->where('kanban_column', $request->status ?? 'todo')
            ->max('kanban_order') ?? 0;

        $task = Task::create([
            'project_id' => $project->id,
            'parent_task_id' => $request->parent_task_id,
            'title' => $request->title,
            'description' => $request->description,
            'status' => $request->status ?? Task::STATUS_TODO,
            'priority' => $request->priority ?? Task::PRIORITY_MEDIUM,
            'assigned_to' => $request->assigned_to,
            'start_date' => $request->start_date,
            'due_date' => $request->due_date,
            'estimated_hours' => $request->estimated_hours,
            'tags' => $request->tags,
            'kanban_column' => $request->status ?? 'todo',
            'kanban_order' => $maxOrder + 1,
        ]);

        $project->logActivity(ProjectActivity::TYPE_TASK_CREATED, "Task '{$task->title}' created", [
            'task_id' => $task->id,
        ]);

        // Send email notification if task is assigned
        if ($task->assigned_to && $task->assignedUser) {
            \Illuminate\Support\Facades\Mail::to($task->assignedUser->email)
                ->send(new \App\Mail\TaskAssignedMail($task, auth()->user()));
        }

        return redirect()->back()->with('success', 'Task created successfully!');
    }

    public function updateTask(Request $request, Task $task)
    {
        $oldStatus = $task->status; // Capture before update

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|in:todo,in_progress,review,done,blocked',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'estimated_hours' => 'nullable|numeric|min:0',
            'tags' => 'nullable|array',
            'checklist' => 'nullable|array',
        ]);

        $task->update($request->only([
            'title',
            'description',
            'status',
            'priority',
            'assigned_to',
            'start_date',
            'due_date',
            'estimated_hours',
            'tags',
            'checklist',
        ]));

        if ($request->has('assignments')) {
            // Simple sync for now (remove all and add new)
            // Ideally we should use sync() if it was a BelongsToMany, but it's MorphMany
            $task->assignments()->delete();
            foreach ($request->assignments as $userId) {
                if ($userId) {
                    $task->assignments()->create(['user_id' => $userId]);
                }
            }
            // Also update legacy assigned_to for now logic (first user)
            $task->assigned_to = count($request->assignments) > 0 ? $request->assignments[0] : null;
            $task->save();
        }

        if ($request->status === Task::STATUS_DONE) {
            $task->complete();
        } else {
            $task->updateProgress();
        }

        $task->project->logActivity(ProjectActivity::TYPE_TASK_UPDATED, "Task '{$task->title}' updated", [
            'task_id' => $task->id,
        ]);

        // Send email notification if status changed
        if ($oldStatus !== $task->status && $task->assignedUser) {
            \Illuminate\Support\Facades\Mail::to($task->assignedUser->email)
                ->send(new \App\Mail\TaskStatusChangedMail($task, $oldStatus, $task->status, auth()->user()->name));
        }

        return redirect()->back()->with('success', 'Task updated successfully!');
    }

    public function destroyTask(Task $task)
    {
        $project = $task->project;
        $taskTitle = $task->title;

        $task->delete();

        $project->logActivity(ProjectActivity::TYPE_TASK_UPDATED, "Task '{$taskTitle}' deleted");

        return redirect()->back()->with('success', 'Task deleted successfully!');
    }

    // ==================== KANBAN BOARD ====================
    public function kanban(Project $project)
    {
        $kanbanBoard = $project->kanbanBoards()->where('is_default', true)->first();

        if (!$kanbanBoard) {
            $kanbanBoard = KanbanBoard::create([
                'project_id' => $project->id,
                'name' => 'Main Board',
                'columns' => KanbanBoard::defaultColumns(),
                'is_default' => true,
            ]);
        }

        $tasksByColumn = [];
        foreach ($kanbanBoard->columns as $column) {
            $tasksByColumn[$column] = $project->tasks()
                ->with(['assignedUser', 'subtasks'])
                ->where('kanban_column', $column)
                ->whereNull('parent_task_id')
                ->orderBy('kanban_order')
                ->get();
        }

        return Inertia::render('Admin/Projects/KanbanBoard', [
            'project' => $project,
            'kanbanBoard' => $kanbanBoard,
            'tasksByColumn' => $tasksByColumn,
        ]);
    }

    public function moveTask(Request $request, Task $task)
    {
        $request->validate([
            'kanban_column' => 'required|string',
            'kanban_order' => 'required|integer|min:0',
        ]);

        $oldColumn = $task->kanban_column;
        $newColumn = $request->kanban_column;

        // Update task column and order
        $task->kanban_column = $newColumn;
        $task->kanban_order = $request->kanban_order;

        // Update status based on column
        $statusMap = [
            'todo' => Task::STATUS_TODO,
            'in_progress' => Task::STATUS_IN_PROGRESS,
            'review' => Task::STATUS_REVIEW,
            'done' => Task::STATUS_DONE,
        ];
        if (isset($statusMap[$newColumn])) {
            $task->status = $statusMap[$newColumn];
        }

        $task->save();

        // Reorder other tasks in the new column
        Task::where('project_id', $task->project_id)
            ->where('kanban_column', $newColumn)
            ->where('id', '!=', $task->id)
            ->where('kanban_order', '>=', $request->kanban_order)
            ->increment('kanban_order');

        if ($task->status === Task::STATUS_DONE) {
            $task->complete();
        } else {
            $task->updateProgress();
        }

        return redirect()->back()->with('success', 'Task moved successfully!');
    }

    // ==================== GANTT CHART ====================
    public function gantt(Project $project)
    {
        $tasks = $project->tasks()
            ->with(['assignedUser', 'dependencies.dependsOnTask'])
            ->whereNull('parent_task_id')
            ->get();

        // Transform tasks for frappe-gantt format
        $ganttTasks = $tasks->map(function ($task) {
            return [
                'id' => (string) $task->id,
                'name' => $task->title,
                'start' => $task->start_date ? $task->start_date->format('Y-m-d') : now()->format('Y-m-d'),
                'end' => $task->due_date ? $task->due_date->format('Y-m-d') : now()->addDays(7)->format('Y-m-d'),
                'progress' => $task->progress_percentage,
                'dependencies' => $task->dependencies->pluck('depends_on_task_id')->map(fn($id) => (string) $id)->join(','),
                'custom_class' => 'priority-' . $task->priority,
            ];
        });

        return Inertia::render('Admin/Projects/GanttChart', [
            'project' => $project,
            'tasks' => $ganttTasks,
        ]);
    }

    // ==================== TIME TRACKING ====================
    public function startTimer(Request $request, Task $task)
    {
        $request->validate([
            'description' => 'nullable|string',
            'is_billable' => 'nullable|boolean',
        ]);

        // Stop any running timers for this user
        TimeLog::where('user_id', auth()->id())
            ->where('is_timer_running', true)
            ->get()
            ->each(fn($log) => $log->stopTimer());

        $timeLog = TimeLog::create([
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'description' => $request->description,
            'started_at' => now(),
            'is_billable' => $request->is_billable ?? false,
            'is_timer_running' => true,
        ]);

        return redirect()->back()->with('success', 'Timer started!');
    }

    public function stopTimer(TimeLog $timeLog)
    {
        if ($timeLog->user_id !== auth()->id()) {
            return redirect()->back()->with('error', 'Unauthorized action.');
        }

        $timeLog->stopTimer();

        return redirect()->back()->with('success', 'Timer stopped! Duration: ' . $timeLog->formatted_duration);
    }

    public function timesheets(Request $request)
    {
        $query = TimeLog::with(['task.project', 'user'])
            ->orderBy('started_at', 'desc');

        if ($request->project_id) {
            $query->whereHas('task', function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('started_at', [$request->start_date, $request->end_date]);
        }

        $timeLogs = $query->paginate(50);

        $projects = Project::select('id', 'name')->get();
        $users = User::select('id', 'name')->get();

        return Inertia::render('Admin/Projects/Timesheets', [
            'timeLogs' => $timeLogs,
            'projects' => $projects,
            'users' => $users,
            'filters' => $request->only(['project_id', 'user_id', 'start_date', 'end_date']),
        ]);
    }

    // ==================== COMMENTS ====================
    public function storeComment(Request $request, Task $task)
    {
        $request->validate([
            'content' => 'required|string',
            'parent_comment_id' => 'nullable|exists:comments,id',
        ]);

        $comment = $task->comments()->create([
            'user_id' => auth()->id(),
            'parent_comment_id' => $request->parent_comment_id,
            'content' => $request->content,
        ]);

        $task->project->logActivity(ProjectActivity::TYPE_COMMENT_ADDED, "Comment added to task '{$task->title}'", [
            'task_id' => $task->id,
            'comment_id' => $comment->id,
        ]);

        // Send email notification to task assignee (if not the commenter)
        if ($task->assignedUser && $task->assigned_to !== auth()->id()) {
            \Illuminate\Support\Facades\Mail::to($task->assignedUser->email)
                ->send(new \App\Mail\CommentAddedMail($task, $comment, auth()->user()->name));
        }

        // Send mention emails
        $users = User::all();
        foreach ($users as $u) {
            if ($u->id !== auth()->id() && str_contains($request->content, '@' . $u->name)) {
                \Illuminate\Support\Facades\Mail::to($u->email)
                    ->send(new \App\Mail\UserMentionedMail($task, $comment, auth()->user(), $u));
            }
        }

        return redirect()->back()->with('success', 'Comment added successfully!');
    }

    public function destroyComment(Comment $comment)
    {
        if ($comment->user_id !== auth()->id() && !auth()->user()->hasRole('admin')) {
            return back()->with('error', 'Unauthorized');
        }
        $comment->delete();
        return back()->with('success', 'Comment deleted');
    }

    public function toggleCommentReaction(Request $request, Comment $comment)
    {
        $request->validate(['emoji' => 'required|string']);

        $existing = $comment->reactions()
            ->where('user_id', auth()->id())
            ->where('emoji', $request->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $comment->reactions()->create([
                'user_id' => auth()->id(),
                'emoji' => $request->emoji
            ]);
        }

        return back();
    }

    // ==================== FILE MANAGEMENT ====================
    // ==================== FILE MANAGEMENT ====================
    public function storeFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB
            'fileable_type' => 'required|string',
            'fileable_id' => 'required|integer',
        ]);

        // Map frontend type map to actual Class
        $map = [
            'task' => \App\Models\Task::class,
            'project' => \App\Models\Project::class,
            'comment' => \App\Models\Comment::class,
        ];

        $class = $map[$request->fileable_type] ?? null;
        if (!$class)
            abort(400, 'Invalid type');

        $path = $request->file('file')->store('project-files', 'public');

        $file = new ProjectFile([
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_path' => $path,
            'file_size' => $request->file('file')->getSize(),
            'mime_type' => $request->file('file')->getMimeType(),
            'uploaded_by' => auth()->id(),
            'fileable_type' => $class,
            'fileable_id' => $request->fileable_id,
        ]);
        $file->save();

        return back()->with('success', 'File uploaded');
    }

    public function downloadFile(ProjectFile $file)
    {
        if (!Storage::disk('public')->exists($file->file_path)) {
            return redirect()->back()->with('error', 'File not found.');
        }
        return Storage::disk('public')->download($file->file_path, $file->file_name);
    }

    public function destroyFile(ProjectFile $file)
    {
        $file->delete(); // Model handles storage deletion
        return back()->with('success', 'File deleted');
    }

    // ==================== DEPENDENCIES & SEARCH ====================
    public function searchTasks(Request $request)
    {
        $query = $request->input('query');
        $excludeId = $request->input('exclude_task_id');
        $projectId = $request->input('project_id');

        $tasks = Task::query()
            ->when($projectId, fn($q) => $q->where('project_id', $projectId))
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->where('title', 'like', "%{$query}%")
            ->take(10)
            ->get(['id', 'title', 'project_id', 'status']);

        return response()->json($tasks);
    }

    public function storeDependency(Request $request, Task $task)
    {
        $validated = $request->validate([
            'depends_on_task_id' => 'required|exists:tasks,id',
            'dependency_type' => 'required|string'
        ]);

        if ($task->id == $validated['depends_on_task_id']) {
            return back()->withErrors(['dependency' => 'Cannot depend on self']);
        }

        // Check if dependency already exists
        $exists = $task->dependencies()->where('depends_on_task_id', $validated['depends_on_task_id'])->exists();
        if (!$exists) {
            $task->dependencies()->create($validated);
        }

        return back()->with('success', 'Dependency added');
    }

    public function destroyDependency(TaskDependency $dependency)
    {
        $dependency->delete();
        return back()->with('success', 'Dependency removed');
    }

    // ==================== SERVICE REQUEST INTEGRATION ====================
    public function convertServiceRequest(ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->project) {
            return redirect()->route('admin.projects.show', $serviceRequest->project)
                ->with('info', 'Service request already converted to project.');
        }

        $project = $serviceRequest->convertToProject();

        return redirect()->route('admin.projects.show', $project)
            ->with('success', 'Service request converted to project successfully!');
    }
}
