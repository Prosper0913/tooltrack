# ToolTrack — Backend Connection Guide

## Project File Structure

```
your-project/
│
├── login.html              ← Login page
├── index.html              ← Main dashboard (tooltrack.html renamed)
├── logout.php              ← Destroys session, redirects to login
│
└── api/
    ├── config.php          ← DB credentials + shared helpers
    ├── auth.php            ← Login / session / current user
    ├── dashboard.php       ← Stats, charts, alerts
    ├── tools.php           ← Tools CRUD
    ├── borrowers.php       ← Borrowers CRUD
    ├── transactions.php    ← Borrow & Return
    └── reports.php         ← CSV downloads + chart data
```

---

## Step 1 — Set Up MySQL Database

Run the SQL file once. In **phpMyAdmin**: click Import → choose `database.sql` → Go.

Or via terminal:
```bash
mysql -u root -p < database.sql
```

This creates the `tooltrack_db` database with all tables.

---

## Step 2 — Configure Your Database Credentials

Open `api/config.php` and edit these 4 lines:

```php
define('DB_HOST', 'localhost');    // usually localhost
define('DB_NAME', 'tooltrack_db');
define('DB_USER', 'root');         // your MySQL username
define('DB_PASS', '');             // your MySQL password
```

---

## Step 3 — Upload Files to Your Server

**Option A — XAMPP (local)**
Copy the whole project folder into: `C:/xampp/htdocs/tooltrack/`
Then open: `http://localhost/tooltrack/login.html`

**Option B — cPanel / Live Server**
Upload all files to `public_html/tooltrack/` via File Manager or FTP.
Then open: `https://yourdomain.com/tooltrack/login.html`

---

## Step 4 — Default Login

```
Email:    admin@school.edu
Password: admin1234
```

To add more admins, insert into the `users` table with a bcrypt password:
```php
echo password_hash('yourpassword', PASSWORD_DEFAULT);
```

---

## API Endpoint Reference

Every endpoint returns:
```json
{ "success": true,  "data": { ... } }
{ "success": false, "message": "Error description" }
```

---

### auth.php

| Method | Body / Params | Returns |
|--------|--------------|---------|
| GET | — | `{ name, role, initials }` |
| POST | `{ email, password }` | `{ id, name, role, initials }` |
| DELETE | — | Destroys session |

---

### tools.php

| Method | Body / Params | What it does |
|--------|--------------|--------------|
| GET | `?page=1&per_page=10&status=available&category=Electronics&search=meter` | Paginated list |
| GET | `?id=5` | Single tool |
| POST | `{ name, code, category, quantity, min_stock, description }` | Create tool |
| PUT | `{ id, name, code, category, quantity, min_stock, description }` | Update tool |
| DELETE | `?id=5` | Delete tool (blocked if has active borrows) |

**Tool object returned:**
```json
{
  "id": 1,
  "name": "Digital Multimeter",
  "code": "TL-DMM-001",
  "category": "Electronics",
  "quantity": 25,
  "available": 18,
  "min_stock": 5,
  "description": "High-precision DMM",
  "status": "available"
}
```

Status is auto-computed:
- `available` — stock above minimum
- `low-stock` — available ≤ min_stock
- `borrowed` — 0 available

---

### borrowers.php

| Method | Body / Params | What it does |
|--------|--------------|--------------|
| GET | `?page=1&per_page=10&type=Student&search=maria` | Paginated list |
| GET | `?id=3` | Single borrower |
| POST | `{ full_name, id_number, type, email, phone }` | Create borrower |
| PUT | `{ id, full_name, id_number, type, email, phone }` | Update borrower |
| DELETE | `?id=3` | Delete (blocked if active_borrows > 0) |

**Borrower object:**
```json
{
  "id": 1,
  "full_name": "Maria Santos",
  "id_number": "2024-0892",
  "type": "Student",
  "email": "maria@school.edu",
  "phone": "0917-111-2222",
  "active_borrows": 1,
  "total_borrows": 12
}
```

---

### transactions.php

**POST — Borrow a tool:**
```json
{
  "type": "borrow",
  "tool_code": "TL-DMM-001",
  "borrower_id": 1,
  "due_date": "2026-05-20",
  "notes": "For lab class"
}
```
Returns: `{ id, txn_id, tool_name, tool_code, borrower, due_date }`

**POST — Return a tool:**
```json
{
  "type": "return",
  "tool_code": "TL-DMM-001",
  "condition": "good",
  "notes": "Returned in perfect condition"
}
```
Returns: `{ id, txn_id, tool_name, returned_by, condition }`

**GET — Borrow history:**
`?type=borrow&status=active` or `&status=returned`

**GET — Return history:**
`?type=return&condition=good` or `&condition=damaged`

---

### dashboard.php

`GET` returns one big object:
```json
{
  "total_tools": 248,
  "available_tools": 186,
  "borrowed_tools": 62,
  "total_borrowers": 126,
  "low_stock_count": 5,
  "active_borrows": 18,
  "weekly_labels": ["Mon","Tue",...],
  "weekly_borrows": [12, 19, ...],
  "weekly_returns": [8, 15, ...],
  "most_borrowed": [{ "name": "...", "count": 156, "pct": 100 }],
  "recent_transactions": [{ "type": "borrow", "tool_name": "...", "borrower": "...", "created_at": "..." }],
  "low_stock_items": [{ "name": "...", "available": 2, "min_stock": 10 }]
}
```

---

### reports.php

| Params | Returns |
|--------|---------|
| `?type=monthly` | `{ labels[], borrows[], returns[] }` for bar chart |
| `?type=category` | `{ labels[], values[] }` for doughnut chart |
| `?type=inventory&download=1` | CSV file download |
| `?type=transactions&download=1` | CSV file download |
| `?type=borrowers&download=1` | CSV file download |
| `?type=overdue&download=1` | CSV file download |

---

## How Frontend Calls the Backend

The frontend uses `apiFetch()` in `index.html` — here is exactly how each action works:

```
[User clicks "Add Borrower" → fills form → clicks Save]
       ↓
saveBorrower() collects form data
       ↓
apiFetch('api/borrowers.php', { method: 'POST', body: JSON.stringify(data) })
       ↓
PHP inserts row → returns { success: true, data: { id, full_name, ... } }
       ↓
showToast("Maria Santos added.") + loadBorrowers() refreshes the table
```

Same pattern for Tools, Transactions, etc.

---

## Common Errors & Fixes

| Error | Cause | Fix |
|-------|-------|-----|
| `Database connection failed` | Wrong credentials in config.php | Edit DB_USER / DB_PASS |
| `404 Not Found` on API calls | Files not in `api/` folder | Check folder structure |
| `CORS error` in browser console | Fetching from different port | Add your frontend URL to `Access-Control-Allow-Origin` in config.php |
| `Class not found` PHP error | PHP version < 8.0 | Check `php -v`, needs PHP 8.0+ |
| Duplicate key error on borrow | Tool code not found | Make sure tool code in DB matches exactly what's scanned |
| Session not persisting | `session_start()` missing | Already included in auth.php — don't remove it |

---

## Changing the API Path

If your `api/` folder is in a different location, edit one line in `index.html`:

```js
// Line ~330 in index.html
const API = 'api';   // ← change this to match your folder path
// e.g.  const API = '/tooltrack/api';
//        const API = 'https://yourdomain.com/tooltrack/api';
```

---

## Adding a New Admin User (PHP snippet)

```php
<?php
// Run this once, then delete the file
require 'api/config.php';
$db = getDB();
$stmt = $db->prepare('INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)');
$stmt->execute([
    'New Admin',
    'newadmin@school.edu',
    password_hash('yourpassword123', PASSWORD_DEFAULT),
    'Department Admin'
]);
echo 'Done. User created.';
```

---

## Quick Checklist Before Going Live

- [ ] Database created and `database.sql` imported
- [ ] `api/config.php` credentials updated
- [ ] Files uploaded to server
- [ ] `login.html` opens without errors
- [ ] Default login works (`admin@school.edu` / `admin1234`)
- [ ] Add a tool → appears in table ✓
- [ ] Add a borrower → appears in table ✓
- [ ] Borrow a tool → available count decreases ✓
- [ ] Return a tool → available count restores ✓
- [ ] Change default admin password in `users` table
