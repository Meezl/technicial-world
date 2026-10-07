SELECT ja.service_request_id,
       sr.request_id,
       ja.technician_id,
       u.name AS technician,
       COUNT(DISTINCT COALESCE(ja.service_sub_task_id, 0)) AS slots,
       SUM(ja.agreed_compensation) AS total_fees,
       MAX(ja.agreed_compensation) AS old_figure
FROM job_assignments ja
JOIN service_requests sr ON sr.id = ja.service_request_id
JOIN technicians t ON t.id = ja.technician_id
JOIN users u ON u.id = t.user_id
WHERE ja.status <> 'declined'
GROUP BY ja.service_request_id, sr.request_id, ja.technician_id, u.name
HAVING COUNT(DISTINCT COALESCE(ja.service_sub_task_id, 0)) > 1
ORDER BY total_fees DESC;
