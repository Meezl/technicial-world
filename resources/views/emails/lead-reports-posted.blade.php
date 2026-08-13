<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9; }
        .header { background-color: #0d6efd; color: white; padding: 10px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { padding: 20px; background-color: white; }
        .footer { margin-top: 20px; font-size: 0.9em; text-align: center; color: #666; }
        .btn { display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Reports ready to review</h2>
        </div>
        <div class="content">
            <p>Hello,</p>
            <p>
                The lead technician on job
                <strong>{{ $serviceRequest->request_id }}</strong>
                has posted {{ $reportCount }}
                {{ $reportCount === 1 ? 'report' : 'reports' }} for the office to
                review. Edit them as needed and release the batch to the client
                when ready — the client receives a single collective update.
            </p>
            <a class="btn" href="{{ url('/admin/jobs/' . $serviceRequest->id) }}">Review reports</a>
        </div>
        <div class="footer">
            <p>Technician World &middot; Internal notification.</p>
        </div>
    </div>
</body>
</html>
