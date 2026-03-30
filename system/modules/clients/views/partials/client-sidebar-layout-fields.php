<?php
/** @var list<string> $sidebarLayoutKeys */
/** @var array<string, mixed> $client */
/** @var \Modules\Clients\Services\ClientFieldCatalogService $fieldCatalog */
/** @var array<int, string|null> $customFieldValues */
$customFieldValues = $customFieldValues ?? [];

$primaryPhone = static function (array $c): string {
    $m = trim((string) ($c['phone_mobile'] ?? ''));
    if ($m !== '') {
        return $m;
    }
    $h = trim((string) ($c['phone_home'] ?? ''));
    if ($h !== '') {
        return $h;
    }
    $w = trim((string) ($c['phone_work'] ?? ''));
    if ($w !== '') {
        return $w;
    }

    return trim((string) ($c['phone'] ?? ''));
};

$boolLabel = static function ($v): string {
    return ((int) $v === 1) ? 'Yes' : 'No';
};

$valOrDash = static function (?string $s): string {
    $s = trim((string) $s);

    return $s !== '' ? $s : '—';
};
?>
<?php if (!empty($sidebarLayoutKeys)): ?>
<h2 class="client-ref-sidebar-heading">Client (layout)</h2>
<dl class="client-ref-sidebar-dl">
<?php foreach ($sidebarLayoutKeys as $sk): ?>
    <?php
    switch ($sk) {
        case 'email':
            echo '<dt>Email</dt><dd>' . htmlspecialchars($valOrDash($client['email'] ?? null)) . '</dd>';
            break;
        case 'summary_primary_phone':
        case 'phone_contact_block':
            $ph = $primaryPhone($client);
            echo '<dt>Primary phone</dt><dd>' . ($ph !== '' ? htmlspecialchars($ph) : '—') . '</dd>';
            break;
        case 'language':
            echo '<dt>Language</dt><dd>' . htmlspecialchars($valOrDash($client['language'] ?? null)) . '</dd>';
            break;
        case 'customer_origin':
            echo '<dt>Customer origin</dt><dd>' . htmlspecialchars($valOrDash($client['customer_origin'] ?? null)) . '</dd>';
            break;
        case 'inactive_flag':
            echo '<dt>Inactive</dt><dd>' . htmlspecialchars($boolLabel($client['inactive_flag'] ?? 0)) . '</dd>';
            break;
        case 'referred_by':
            echo '<dt>Referred by</dt><dd>' . htmlspecialchars($valOrDash($client['referred_by'] ?? null)) . '</dd>';
            break;
        default:
            $cid = $fieldCatalog->parseCustomFieldId($sk);
            if ($cid !== null) {
                $defs = [];
                foreach (($customFieldDefinitions ?? []) as $d) {
                    $defs[(int) $d['id']] = $d;
                }
                $def = $defs[$cid] ?? null;
                if ($def !== null) {
                    $tv = $customFieldValues[$cid] ?? null;
                    echo '<dt>' . htmlspecialchars((string) $def['label']) . '</dt><dd>' . htmlspecialchars($valOrDash($tv !== null ? (string) $tv : null)) . '</dd>';
                }
                break;
            }
            $defs = $fieldCatalog->systemFieldDefinitions();
            if (isset($defs[$sk]) && ($defs[$sk]['kind'] ?? '') === 'system_scalar' && isset($defs[$sk]['column'])) {
                $col = (string) $defs[$sk]['column'];
                $raw = $client[$col] ?? null;
                echo '<dt>' . htmlspecialchars((string) $defs[$sk]['label']) . '</dt><dd>' . htmlspecialchars($valOrDash($raw !== null ? (string) $raw : null)) . '</dd>';
            }
            break;
    }
    ?>
<?php endforeach; ?>
</dl>
<?php endif; ?>
