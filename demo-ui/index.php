<?php
require __DIR__ . '/lib.php';
page_head('Dashboard', 'index.php');

try {
    $live = or_api('GET', '/live?domain=' . rawurlencode($CFG['domain']));
    $regs = $live['registrations'] ?? [];
    $calls = $live['channels'] ?? [];
    $agents = $live['agents'] ?? [];
    $queues = $live['queues'] ?? [];
?>
<div class="cards">
  <div class="card"><b><?= count($regs) ?></b><span>Registered phones</span></div>
  <div class="card"><b><?= count($calls) ?></b><span>Call legs now</span></div>
  <div class="card"><b><?= count($queues) ?></b><span>Queues</span></div>
  <div class="card"><b><?= count($agents) ?></b><span>Agents</span></div>
</div>

<h2>Registrations</h2>
<?php render_table($regs, ['Extension' => 'user', 'Contact' => 'contact', 'Phone' => 'user_agent']); ?>

<h2>Calls in progress</h2>
<?php render_table($calls, [
    'From' => fn($c) => $c['caller_id_number'] ?? $c['cid_number'] ?? '',
    'To'   => fn($c) => $c['destination'] ?? $c['dest'] ?? '',
    'State' => 'state',
]); ?>

<h2>Queues</h2>
<?php render_table($queues, [
    'Queue' => fn($q) => ($q['name'] ?? '') . ' (' . ($q['extension'] ?? '') . ')',
    'Strategy' => 'strategy', 'Waiting' => 'waiting', 'Free agents' => 'available', 'On call' => 'on_call', 'On break' => 'on_break',
]); ?>

<h2>Agents</h2>
<?php render_table($agents, [
    'Agent' => 'name', 'Ext' => 'extension',
    'Status' => fn($a) => ($a['status'] ?? '') . (!empty($a['aux']) ? ' (' . $a['aux'] . ')' : ''),
    'Queues' => fn($a) => implode(', ', $a['queues'] ?? []),
]); ?>
<p class="muted">Reload the page to refresh. A live version would use the switch's <code>/api/ws</code> WebSocket (<code>{"t":"live","on":true}</code>).</p>
<?php
} catch (Throwable $e) {
    show_error($e);
    echo '<p class="muted">Check <code>demo-ui/config.php</code>, or set <code>mock</code> to <code>true</code> to look around without a switch.</p>';
}
page_foot();
