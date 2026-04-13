<x-mail::message>
# You've Been Assigned to a Project

You have been assigned to the project **{{ $project->name }}** by **{{ $assignedBy->name }}**.

**Status:** {{ ucfirst(str_replace('_', ' ', $project->status)) }}
@if($project->start_date)
**Start Date:** {{ $project->start_date->format('M d, Y') }}
@endif
@if($project->due_date)
**Due Date:** {{ $project->due_date->format('M d, Y') }}
@endif

<x-mail::button :url="$url">
View Project Details
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
