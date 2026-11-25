<?php
require __DIR__ . '/lib.php';

$msg = $err = null;
$dests = [];
try {
    // Destinations the switch offers (extensions, queues, ring groups, voicemail ...), as {group,label,value}.
    $meta = or_api('GET', '/d/' . rawurlencode($CFG['domain']) . '/meta');
    $dests = $meta['destinations'] ?? [];
} catch (Throwable $e) { $err = $e; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $name = trim($_POST['name'] ?? '');
        $num = trim($_POST['number'] ?? '');
        if ($name === '' || !preg_match('/^\d{2,10}$/', $num)) throw new RuntimeException('An IVR needs a name and a numeric number.');
        $allowed = array_column($dests, 'value');
        $options = [];
        foreach ($_POST['key'] ?? [] as $i => $digit) {
            $digit = trim($digit);
            $to = $_POST['to'][$i] ?? '';
            if ($digit === '' || $to === '') continue;
            if (!preg_match('/^[0-9*#]$/', $digit)) throw new RuntimeException("Key '$digit' must be one of 0-9, * or #.");
            if (!in_array($to, $allowed, true)) throw new RuntimeException('Unknown destination for key ' . $digit);
            $options[] = ['ivr_menu_option_digits' => $digit, 'action' => $to, 'ivr_menu_option_description' => ''];
        }
        if (!$options) throw new RuntimeException('Add at least one menu key.');
        $body = [
            'ivr_menu_name' => $name, 'ivr_menu_extension' => $num,
            'ivr_menu_timeout' => '3000', 'ivr_menu_inter_digit_timeout' => '2000',
            'ivr_menu_max_failures' => '3', 'ivr_menu_max_timeouts' => '3', 'ivr_menu_digit_len' => '5',
            'ivr_menu_enabled' => 'true', 'options' => $options,
        ];
        // Optional greeting: a file uploaded in the portal (Settings > Sounds & music), e.g. prompts/welcome.wav
        if (trim($_POST['greeting'] ?? '') !== '') $body['ivr_menu_greet_long'] = trim($_POST['greeting']);
        or_api('POST', dpath('ivrs'), $body);
        $msg = "IVR $name ($num) created. Dial $num from a phone to try it.";
    } catch (Throwable $e) { $err = $e; }
}

page_head('IVR menus', 'ivrs.php');
if ($msg) echo '<div class="ok">' . h($msg) . '</div>';
if ($err) show_error($err);

try {
    $rows = or_api('GET', dpath('ivrs'));
    render_table($rows, [
        'Number' => 'ivr_menu_extension', 'Name' => 'ivr_menu_name', 'Greeting' => 'ivr_menu_greet_long',
        'Keys' => fn($r) => isset($r['options']) ? count($r['options']) : '',
    ]);
} catch (Throwable $e) { show_error($e); }

function dest_select(array $dests, string $name): string {
    $out = '<select name="' . $name . '[]"><option value="">- not used -</option>';
    $group = null;
    foreach ($dests as $d) {
        if ($d['group'] !== $group) { if ($group !== null) $out .= '</optgroup>'; $group = $d['group']; $out .= '<optgroup label="' . h($group) . '">'; }
        $out .= '<option value="' . h($d['value']) . '">' . h($d['label']) . '</option>';
    }
    return $out . ($group !== null ? '</optgroup>' : '') . '</select>';
}
?>
<h2>Add an IVR menu</h2>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Name <input name="name" required placeholder="Main menu"></label>
  <label>Number <input name="number" required pattern="\d{2,10}" placeholder="5000"></label>
  <label>Greeting file (optional) <input name="greeting" placeholder="prompts/welcome.wav"></label>
  <fieldset><legend>Menu keys</legend>
    <?php for ($i = 1; $i <= 4; $i++): ?>
      <div class="row">Press <input name="key[]" size="2" maxlength="1" value="<?= $i ?>"> goes to <?= dest_select($dests, 'to') ?></div>
    <?php endfor; ?>
  </fieldset>
  <button>Create</button>
</form>
<?php page_foot();
