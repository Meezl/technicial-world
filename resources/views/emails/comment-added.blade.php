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

        .comment-box {
            background: #f8f9fa;
            border-left: 4px solid #2563eb;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }

        .comment-box .author {
            font-weight: bold;
            color: #053272;
            margin-bottom: 10px;
        }

        .comment-box .text {
            color: #555;
            font-style: italic;
        }

        .task-info {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
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
            <p>Hello,</p>
            <p>A new comment has been added to a task you're involved with.</p>

            <p class="task-info">
                <strong>Task:</strong> {{ $task->name }}<br>
                <strong>Project:</strong> {{ $task->project->name }}
            </p>

            <div class="comment-box">
                <div class="author">{{ $commenterName }} wrote:</div>
                <div class="text">"{{ Str::limit($comment->content, 300) }}"</div>
            </div>

            <a href="{{ url('/admin/projects/' . $task->project_id) }}" class="btn">View Conversation</a>
        </div>
        <div class="footer">
            <p>© {{ date('Y') }} Technician World. All rights reserved.</p>
        </div>
    </div>
</body>

</html>