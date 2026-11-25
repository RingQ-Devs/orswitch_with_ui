<?php
require __DIR__ . '/lib.php';

$strategies = ['longest-idle-agent', 'ring-all', 'round-robin', 'top-down', 'agent-with-least-talk-time',
               'agent-with-fewest-calls', 'sequentially-by-agent-order', 'random', 'ring-progressively'];
$msg = $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $name = trim($_POST['name'] ?? '');
        $num = trim($_POST['number'] ?? '');
        if ($name === '' || !preg_match('/^\d{2,10}$/', $num)) throw new RuntimeException('A queue needs a name and a numeric number.');
        if (!in_array($_POST['strategy'] ?? '', $strategies, true)) throw new RuntimeException('Unknown strategy.');
        $agents = [];
        foreach (preg_split('/[\s,]+/', trim($_POST['agents'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $i => $e) {
            if (!preg_match('/^\d{2,10}$/', $e)) throw new RuntimeException("Agent '$e' is not an extension number.");
            $agents[] = ['extension' => $e, 'tier_level' => 1, 'tier_position' => $i + 1];
        }
        or_api('POST', dpath('queues'), [
            'queue_name' => $name, 'queue_extension' => $num, 'queue_strategy' => $_POST['strategy'],
            'queue_moh_sound' => 'local_stream://default', 'queue_max_wait_time' => '0',
            'queue_max_wait_time_with_no_agent' => '90', 'queue_max_wait_time_with_no_agent_time_reached' => '5',
            'queue_tier_rules_apply' => 'false', 'queue_discard_abandoned_after' => '900', 'record' => false,
            'agents' => $agents,
        ]);
        $msg = "Queue $name ($num) created. Dial $num from a phone to test it, then log an agent in with *22.";
    } catch (Throwable $e) { $err = $e; }
}

page_head('Queues', 'queues.php');
if ($msg) echo '<div class="ok">' . h($msg) . '</div>';
if ($err) show_error($err);

try {
    $rows = or_api('GET', dpath('queues'));
    $live = or_api('GET', '/live?domain=' . rawurlencode($CFG['domain']));
    $byNum = [];
    foreach ($live['queues'] ?? [] as $q) $byNum[$q['extension'] ?? ''] = $q;
    render_table($rows, [
        'Number' => 'queue_extension', 'Name' => 'queue_name', 'Strategy' => 'queue_strategy',
        'Waiting' => fn($r) => $byNum[$r['queue_extension']]['waiting'] ?? '-',
        'Free / logged in' => function ($r) use ($byNum) {
            $q = $byNum[$r['queue_extension']] ?? null;
            return $q ? ($q['available'] ?? 0) . ' / ' . ($q['logged_in'] ?? 0) : '-';
        },
    ]);
} catch (Throwable $e) { show_error($e); }
?>
<h2>Add a queue</h2>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Name <input name="name" required placeholder="Sales"></label>
  <label>Number <input name="number" required pattern="\d{2,10}" placeholder="3000"></label>
  <label>Strategy
    <select name="strategy"><?php foreach ($strategies as $s) echo '<option>' . h($s) . '</option>'; ?></select>
  </label>
  <label>Agents (extensions, comma separated) <input name="agents" placeholder="1001, 1002"></label>
  <button>Create</button>
</form>
<p class="muted">Agents log in on their phone with <code>*22</code>, go on break with <code>*23</code>.</p>
<?php page_foot();
