<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';

require_login_api();
require_method('GET');
$pdo = db();

$totalContacts = (int)$pdo->query('SELECT COUNT(*) c FROM contacts')->fetch()['c'];

$openDeals = $pdo->query("SELECT COUNT(*) c, COALESCE(SUM(value),0) v FROM deals WHERE stage NOT IN ('Won','Lost')")->fetch();

$wonThisMonth = $pdo->query("
    SELECT COUNT(*) c, COALESCE(SUM(value),0) v FROM deals
    WHERE stage = 'Won' AND YEAR(updated_at) = YEAR(CURDATE()) AND MONTH(updated_at) = MONTH(CURDATE())
")->fetch();

$tasksDueWeek = (int)$pdo->query("
    SELECT COUNT(*) c FROM tasks WHERE done = 0 AND due_date IS NOT NULL AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
")->fetch()['c'];

$tasksOverdue = (int)$pdo->query("
    SELECT COUNT(*) c FROM tasks WHERE done = 0 AND due_date IS NOT NULL AND due_date < CURDATE()
")->fetch()['c'];

$stageBreakdown = $pdo->query("
    SELECT stage, COUNT(*) c, COALESCE(SUM(value),0) v FROM deals GROUP BY stage
")->fetchAll();

$categoryBreakdown = $pdo->query("
    SELECT p.category, COUNT(d.id) c, COALESCE(SUM(d.value),0) v
    FROM deals d JOIN programs p ON p.id = d.program_id
    WHERE d.stage NOT IN ('Lost')
    GROUP BY p.category
")->fetchAll();

$upcomingTasks = $pdo->query("
    SELECT t.id, t.title, t.due_date, t.priority, c.name AS contact_name
    FROM tasks t LEFT JOIN contacts c ON c.id = t.contact_id
    WHERE t.done = 0
    ORDER BY t.due_date IS NULL, t.due_date ASC
    LIMIT 6
")->fetchAll();

json_out([
    'total_contacts' => $totalContacts,
    'open_deals_count' => (int)$openDeals['c'],
    'open_deals_value' => (float)$openDeals['v'],
    'won_this_month_count' => (int)$wonThisMonth['c'],
    'won_this_month_value' => (float)$wonThisMonth['v'],
    'tasks_due_week' => $tasksDueWeek,
    'tasks_overdue' => $tasksOverdue,
    'stage_breakdown' => $stageBreakdown,
    'category_breakdown' => $categoryBreakdown,
    'upcoming_tasks' => $upcomingTasks,
]);
