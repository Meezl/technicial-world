-- ============================================================================
--  Orphan / stale JobAssignment diagnostic
--  ============================================================================
--
--  Purpose: find JobAssignment rows that STILL count against the labour
--  budget commitment (status IN pending/accepted/completed) but are almost
--  certainly ghosts from an earlier attempt — e.g. the admin retried an
--  assignment and the previous one was never cancelled.
--
--  This is the query that will surface the ghost that made REQ-X6HTRO
--  "block above 15k" when the actual budget was 70k.
--
--  Read-only. Nothing here writes. Every SELECT is scoped to a case that
--  ops should almost always want to eyeball before running the fixup at
--  the bottom.
--
--  Run in Railway's DB shell or any MySQL client with SELECT privilege.
--  Wrap the DELETE at the very bottom in a transaction and dry-run first.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. Overall summary: for every SR, how many "counting" assignments exist
--    (direct + sub-task) and what's their committed total? Anything over
--    the labour budget is guaranteed ghost data; anything with 3+ direct
--    assignments per SR deserves a look.
-- ----------------------------------------------------------------------------
SELECT
    sr.id                                                    AS sr_id,
    sr.request_id                                            AS req_id,
    srb.labor_budget                                         AS labour_budget,
    COUNT(CASE WHEN ja.service_sub_task_id IS NULL THEN 1 END)  AS direct_assignments,
    COUNT(CASE WHEN ja.service_sub_task_id IS NOT NULL THEN 1 END) AS subtask_assignments,
    ROUND(SUM(CASE WHEN ja.service_sub_task_id IS NULL THEN ja.agreed_compensation ELSE 0 END), 2) AS direct_committed,
    ROUND(SUM(CASE WHEN ja.service_sub_task_id IS NOT NULL THEN ja.agreed_compensation ELSE 0 END), 2) AS subtask_committed,
    ROUND(SUM(ja.agreed_compensation), 2)                    AS total_committed,
    ROUND(srb.labor_budget - COALESCE(SUM(ja.agreed_compensation), 0), 2) AS remaining
FROM service_requests sr
JOIN service_request_budgets srb ON srb.service_request_id = sr.id
LEFT JOIN job_assignments ja
       ON ja.service_request_id = sr.id
      AND ja.status IN ('pending', 'accepted', 'completed')
GROUP BY sr.id, sr.request_id, srb.labor_budget
HAVING total_committed > labour_budget          -- overspent (ghost almost certain)
    OR direct_assignments > 1                    -- multiple direct assignments = suspect
    OR subtask_assignments > (SELECT COUNT(*) FROM service_sub_tasks st WHERE st.service_request_id = sr.id)
ORDER BY (total_committed - labour_budget) DESC, direct_assignments DESC;


-- ----------------------------------------------------------------------------
-- 2. Drill into a specific REQ. Replace 'REQ-X6HTRO' with the request_id
--    you're investigating. Shows every counting assignment on the SR so
--    ops can see which technician / fee combos look wrong.
-- ----------------------------------------------------------------------------
SELECT
    ja.id                        AS assignment_id,
    ja.status,
    ja.service_sub_task_id,
    sst.title                    AS sub_task_title,
    t.technician_id              AS tech_ref,
    u.name                       AS technician_name,
    ja.agreed_compensation,
    ja.assigned_by,
    admin.name                   AS assigned_by_name,
    ja.created_at,
    ja.updated_at,
    ja.reassignment_reason,
    ja.reassigned_from
FROM job_assignments ja
JOIN service_requests sr    ON sr.id = ja.service_request_id
LEFT JOIN service_sub_tasks sst ON sst.id = ja.service_sub_task_id
LEFT JOIN technicians t     ON t.id = ja.technician_id
LEFT JOIN users u           ON u.id = t.user_id
LEFT JOIN users admin       ON admin.id = ja.assigned_by
WHERE sr.request_id = 'REQ-X6HTRO'
ORDER BY ja.service_sub_task_id NULLS FIRST, ja.created_at;
-- NOTE: 'NULLS FIRST' is standard SQL but not MySQL. On MySQL replace with:
--   ORDER BY (ja.service_sub_task_id IS NOT NULL), ja.created_at;


-- ----------------------------------------------------------------------------
-- 3. High-confidence ghost candidates: direct assignments where the SR
--    currently points at a DIFFERENT technician_id, or where the tech is
--    no longer on the SR's active roster. These are safe-to-cancel bets.
-- ----------------------------------------------------------------------------
SELECT
    ja.id           AS assignment_id,
    sr.request_id   AS req_id,
    ja.status,
    ja.technician_id            AS assignment_tech_id,
    sr.technician_id            AS current_sr_tech_id,
    sr.lead_technician_id       AS current_lead_id,
    ja.agreed_compensation,
    ja.created_at,
    ja.updated_at
FROM job_assignments ja
JOIN service_requests sr ON sr.id = ja.service_request_id
WHERE ja.service_sub_task_id IS NULL
  AND ja.status IN ('pending', 'accepted')
  AND ja.technician_id NOT IN (
        COALESCE(sr.technician_id, 0),
        COALESCE(sr.lead_technician_id, 0)
      )
ORDER BY ja.created_at DESC;


-- ----------------------------------------------------------------------------
-- 4. Sub-task ghosts: assignments whose service_sub_task_id points to a
--    sub-task where that technician is no longer the assigned one.
-- ----------------------------------------------------------------------------
SELECT
    ja.id           AS assignment_id,
    sr.request_id   AS req_id,
    sst.id          AS sub_task_id,
    sst.title       AS sub_task_title,
    ja.status,
    ja.technician_id            AS assignment_tech_id,
    sst.technician_id           AS current_subtask_tech_id,
    ja.agreed_compensation,
    ja.created_at
FROM job_assignments ja
JOIN service_requests sr ON sr.id = ja.service_request_id
JOIN service_sub_tasks sst ON sst.id = ja.service_sub_task_id
WHERE ja.status IN ('pending', 'accepted')
  AND (sst.technician_id IS NULL OR sst.technician_id != ja.technician_id)
ORDER BY ja.created_at DESC;


-- ----------------------------------------------------------------------------
-- 5. FIX (destructive). Only run after eyeballing queries 3 and 4.
--    Marks the identified ghosts as 'reassigned' with a diagnostic note so
--    they stop counting against labour capacity but stay in the DB for audit.
--    Never DELETE — an audit trail matters more than a clean table.
--
--    RECOMMENDED: run inside a transaction, verify affected row count,
--    ROLLBACK if it looks wrong.
-- ----------------------------------------------------------------------------
-- BEGIN;
--
-- -- Direct-assignment ghosts (matches query 3)
-- UPDATE job_assignments ja
-- JOIN service_requests sr ON sr.id = ja.service_request_id
-- SET ja.status = 'reassigned',
--     ja.reassignment_reason = CONCAT('Auto-closed as orphan on ', NOW(),
--         ' — technician no longer matches SR.technician_id or lead_technician_id.'),
--     ja.updated_at = NOW()
-- WHERE ja.service_sub_task_id IS NULL
--   AND ja.status IN ('pending', 'accepted')
--   AND ja.technician_id NOT IN (
--         COALESCE(sr.technician_id, 0),
--         COALESCE(sr.lead_technician_id, 0)
--       );
--
-- -- Sub-task-assignment ghosts (matches query 4)
-- UPDATE job_assignments ja
-- JOIN service_sub_tasks sst ON sst.id = ja.service_sub_task_id
-- SET ja.status = 'reassigned',
--     ja.reassignment_reason = CONCAT('Auto-closed as orphan on ', NOW(),
--         ' — technician no longer matches ServiceSubTask.technician_id.'),
--     ja.updated_at = NOW()
-- WHERE ja.status IN ('pending', 'accepted')
--   AND (sst.technician_id IS NULL OR sst.technician_id != ja.technician_id);
--
-- -- Verify: re-run query 1. Overspent rows should be gone.
-- -- If it looks right: COMMIT;
-- -- If it looks wrong: ROLLBACK;
