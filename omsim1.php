<?php
// --- DATABASE CONFIGURATION ---
$DB_HOST = 'localhost';
$DB_NAME = 'qr_ScannerDB';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

// Create (or reuse) a PDO connection
function db(): PDO {
    static $pdo = null;
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;
    if ($pdo === null) {
        $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    }
    return $pdo;
}

// ==== HANDLE JSON POST TO SAVE ATTENDEE ====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
        exit;
    }

    // Extract parsed data from QR code
    $name                = trim($data['name'] ?? '');
    $address             = trim($data['address'] ?? '');
    $occupation_position = trim($data['occupation_position'] ?? '');
    $agency              = trim($data['agency'] ?? '');
    $contact_details     = trim($data['contact_details'] ?? '');
    $sex                 = trim($data['sex'] ?? '');

    if ($name === '' || $address === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare("INSERT INTO qr_attendees (name, address, occupation_position, agency, contact_details, sex) VALUES (:name, :address, :occupation_position, :agency, :contact_details, :sex)");
        $stmt->execute([
            ':name' => $name,
            ':address' => $address,
            ':occupation_position' => $occupation_position,
            ':agency' => $agency,
            ':contact_details' => $contact_details,
            ':sex' => $sex,
        ]);

        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB error', 'detail' => $e->getMessage()]);
    }
    exit;
}

// ==== IF NOT POST, RENDER SCANNER PAGE ====
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>QR Code Scanner → Attendees</title>
  <style>
    :root { --card: #fff; --text: #111; --muted: #666; --accent: #2563eb; }
    * { box-sizing: border-box; }
    body {
      margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji", "Segoe UI Emoji";
      background: #f5f7fb; color: var(--text); display: flex; min-height: 100vh; align-items: center; justify-content: center;
    }
    .wrap { width: 100%; max-width: 900px; padding: 24px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .card { background: var(--card); border-radius: 16px; padding: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.06); }
    h1 { margin: 0 0 12px; font-size: 22px; }
    video { width: 100%; border-radius: 12px; border: 2px solid #e5e7eb; }
    .result { font-size: 16px; line-height: 1.4; padding: 12px; background:#f1f5f9; border-radius: 12px; min-height: 48px; }
    .btn {
      display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 12px; border: 1px solid #d1d5db;
      background: #fff; cursor: pointer; font-weight: 600; text-decoration: none;
    }
    .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
    .muted { color: var(--muted); font-size: 13px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
    th { background: #f8fafc; position: sticky; top: 0; }
    code { word-break: break-word; }
    @media (max-width: 820px) { .grid { grid-template-columns: 1fr; } }
  </style>
  <script type="module" src="https://fastly.jsdelivr.net/npm/barcode-detector@3/dist/es/polyfill.min.js"></script>
</head>
<body>
  <div class="wrap">
    <div class="grid">
      <div class="card">
        <h1>QR Code Scanner</h1>
        <video id="video" autoplay muted playsinline></video>
        <p class="muted">Allow camera access. On mobile, it will prefer the back camera.</p>
        <div class="result" id="result">Waiting for QR...</div>
        <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn primary" id="saveBtn" disabled>Save to Database</button>
          <button class="btn" id="toggleAuto">Auto-Save: <span id="autoState">ON</span></button>
        </div>
      </div>

      <div class="card">
        <h1>Recent Attendees</h1>
        <div class="muted" style="margin-bottom:8px;">(Last 10 saved attendees)</div>
        <div style="max-height: 340px; overflow: auto;">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Agency</th>
                <th>Contact</th>
              </tr>
            </thead>
            <tbody>
              <?php
              try {
                  $pdo = db();
                  $rows = $pdo->query('SELECT id, name, agency, contact_details FROM qr_attendees ORDER BY id DESC LIMIT 10')->fetchAll();
                  foreach ($rows as $r) {
                      echo '<tr>';
                      echo '<td>'.htmlspecialchars((string)$r['id']).'</td>';
                      echo '<td>'.htmlspecialchars((string)$r['name']).'</td>';
                      echo '<td>'.htmlspecialchars((string)$r['agency']).'</td>';
                      echo '<td>'.htmlspecialchars((string)$r['contact_details']).'</td>';
                      echo '</tr>';
                  }
              } catch (Throwable $e) {
                  echo '<tr><td colspan="4" class="muted">DB error: '.htmlspecialchars($e->getMessage()).'</td></tr>';
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script type="module">
    import { BarcodeDetector } from 'https://fastly.jsdelivr.net/npm/barcode-detector@3/dist/es/ponyfill.min.js';

    const video = document.getElementById('video');
    const resultDiv = document.getElementById('result');
    const saveBtn = document.getElementById('saveBtn');
    const toggleAutoBtn = document.getElementById('toggleAuto');
    const autoStateEl = document.getElementById('autoState');

    let lastValue = '';
    let saving = false;
    let autoSave = true;
    let lastSavedAt = 0;

    toggleAutoBtn.addEventListener('click', () => {
      autoSave = !autoSave;
      autoStateEl.textContent = autoSave ? 'ON' : 'OFF';
    });

    saveBtn.addEventListener('click', () => {
      if (lastValue) handleSave(lastValue);
    });

    async function startCamera() {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.srcObject = stream;
        await new Promise(r => video.onloadedmetadata = r);
      } catch (err) {
        resultDiv.textContent = 'Camera access denied or unavailable.';
        console.error(err);
      }
    }

    function parseQRData(text) {
      let data = { name: "", address: "", occupation_position: "", agency: "", contact_details: "", sex: "" };
      const parts = text.split(" / ");
      parts.forEach(part => {
        const [key, ...rest] = part.split(":");
        if (!key || !rest.length) return;
        const value = rest.join(":").trim();
        const k = key.trim().toLowerCase();
        if (k.includes("name")) data.name = value;
        else if (k.includes("address")) data.address = value;
        else if (k.includes("occupation") || k.includes("position")) data.occupation_position = value;
        else if (k.includes("agency")) data.agency = value;
        else if (k.includes("contact") || k.includes("mobile") || k.includes("email")) data.contact_details = value;
        else if (k.includes("sex")) data.sex = value;
      });
      return data;
    }

    async function handleSave(value) {
      const parsedData = parseQRData(value);
      if (!parsedData.name || !parsedData.address) {
        resultDiv.textContent = 'Invalid QR format. Missing required fields.';
        return;
      }
      await saveToDB(parsedData);
    }

    async function saveToDB(data) {
      if (saving) return;
      saving = true;
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';

      try {
        const resp = await fetch(location.pathname, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        const res = await resp.json();
        if (res.ok) {
          lastSavedAt = Date.now();
          resultDiv.textContent = 'Saved ✓ ID ' + res.id + ' — ' + (data.name || '');
        } else {
          resultDiv.textContent = 'Save failed: ' + (res.error || 'Unknown error');
        }
      } catch (err) {
        resultDiv.textContent = 'Network/Server error while saving.';
        console.error(err);
      } finally {
        saving = false;
        saveBtn.textContent = 'Save to Database';
      }
    }

    async function scanLoop() {
      const detector = new BarcodeDetector({ formats: ['qr_code'] });
      const tick = async () => {
        try {
          const codes = await detector.detect(video);
          if (codes && codes.length) {
            const value = codes[0].rawValue || '';
            if (value && value !== lastValue) {
              lastValue = value;
              resultDiv.textContent = 'QR: ' + value;
              saveBtn.disabled = false;

              const now = Date.now();
              if (autoSave && now - lastSavedAt > 2000) {
                handleSave(value);
              }
            }
          }
        } catch {}
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }

    await startCamera();
    await scanLoop();
  </script>
</body>
</html>
