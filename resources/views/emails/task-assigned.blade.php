<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            background: linear-gradient(135deg, #00334b 0%, #053272 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
        }

        .content {
            padding: 30px;
        }

        .task-card {
            background: #f8f9fa;
            border-left: 4px solid #053272;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .task-card h3 {
            margin: 0 0 10px;
            color: #053272;
        }

        .task-card p {
            margin: 5px 0;
            color: #666;
        }

        .btn {
            display: inline-block;
            background: #053272;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
        }

        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>TECHNICIAN WORLD</h1>
        </div>
        <div class="content">
            <p>Hello {{ $task->assignee->name ?? 'Team Member' }},</p>
            <p>A new task has been assigned to you by <strong>{{ $assignedBy->name }}</strong>.</p>

            <div class="task-card">
                <h3>{{ $task->name }}</h3>
                <p><strong>Project:</strong> {{ $task->project->name }}</p>
                @if($task->description)
                    <p><strong>Description:</strong> {{ Str::limit($task->description, 150) }}</p>
                @endif
                @if($task->due_date)
                    <p><strong>Due Date:</strong> {{ \Carbon\Carbon::parse($task->due_date)->format('M d, Y') }}</p>
                @endif
                <p><strong>Priority:</strong> {{ ucfirst($task->priority ?? 'Medium') }}</p>
            </div>

            <p>Please log in to your dashboard to view the full details and start working on this task.</p>

            <a href="{{ url('/admin/projects/' . $task->project_id) }}" class="btn">View Task</a>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>

</html>