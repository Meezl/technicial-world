<x-mail::message>
# You've Been Mentioned!

Hello **{{ $mentionedUser->name }}**,

**{{ $mentionedBy->name }}** mentioned you in a comment on the task **{{ $task->title }}** (Project: {{ $task->project->name }}).

<x-mail::panel>
"{{ strip_tags($comment->content) }}"
</x-mail::panel>

<x-mail::button :url="$url">
View Task & Reply
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
