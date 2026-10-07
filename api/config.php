<?php
// ================================================================
//  api/config.php  —  included by every api/*.php JSON endpoint.
//  Loads the shared session/DB/role setup from bootstrap.php, then
//  layers on the JSON content-type + CORS headers that only an API
//  response should send. HTML pages (index.php) require bootstrap.php
//  directly instead, so they don't get an application/json header
//  slapped on an HTML response.
// ================================================================
require_once __DIR__ . '/bootstrap.php';

// ── CORS headers ─────────────────────────────────────────────
// NOTE: this app is currently served same-origin (index.php and
// api/*.php on the same host), so the browser doesn't apply CORS
// to these requests at all — sessions/cookies just work. If you
// ever split the frontend onto a different origin, '*' below is
// invalid together with credentials=true (browsers will reject
// it) — replace '*' with your exact frontend origin at that point.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');          // tighten in production
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Shared validation helpers ───────────────────────────────────
// School's official student ID format. Only enforced for type=Student
// — Faculty/Staff use the school's HR numbering (not specified here)
// and Guest is by definition someone outside that system, so neither
// is forced through the student ID shape.
//
// Matches the client-side check already enforced in app.js's
// saveBorrower(): /^\d{8,10}$/ — "8 to 10 digits, numbers only".
// If your school's actual ID format is different (e.g. the hyphenated
// "2024-00123" mentioned in borrowers.php's comment), update BOTH this
// regex and the one in app.js so client and server agree.
    
