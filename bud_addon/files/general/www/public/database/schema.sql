-- BUD Database Schema (SQLite)

BEGIN TRANSACTION;

-- 1. Suppliers Table
CREATE TABLE IF NOT EXISTS suppliers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  contact_person TEXT,
  email TEXT,
  phone TEXT,
  address TEXT,
  notes TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  is_active BOOLEAN DEFAULT 1
);

-- 2. Stock Items Table
CREATE TABLE IF NOT EXISTS stock_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_id INTEGER,
  name TEXT NOT NULL,
  sku TEXT,
  category TEXT DEFAULT 'Other' CHECK(category IN ('Raw Material', 'Finished Product', 'Packaging', 'Sticker', 'Insert', 'Other')),
  description TEXT,
  quantity DECIMAL(10, 2) DEFAULT 0.00,
  unit TEXT DEFAULT 'units',
  reorder_level DECIMAL(10, 2) DEFAULT 0.00,
  is_controlled BOOLEAN DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
);

-- 3. Audit Log
CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  table_name TEXT NOT NULL,
  record_id INTEGER NOT NULL,
  action TEXT CHECK(action IN ('INSERT', 'UPDATE', 'DELETE')) NOT NULL,
  changed_by TEXT DEFAULT 'SYSTEM',
  old_values JSON,
  new_values JSON,
  timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 5. Cleaning Schedules
CREATE TABLE IF NOT EXISTS cleaning_schedules (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT,
  frequency TEXT CHECK(frequency IN ('Daily', 'Weekly', 'Fortnightly', 'Monthly')) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  is_active BOOLEAN DEFAULT 1
);

-- 6. Cleaning Logs
CREATE TABLE IF NOT EXISTS cleaning_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schedule_id INTEGER NOT NULL,
  staff_name TEXT NOT NULL,
  completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  notes TEXT,
  FOREIGN KEY (schedule_id) REFERENCES cleaning_schedules(id)
);

-- 7. Chain of Custody
CREATE TABLE IF NOT EXISTS chain_of_custody (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  form_date DATE NOT NULL,
  origin TEXT DEFAULT 'Main Facility',
  destination TEXT NOT NULL,
  receiver_id INTEGER,
  transported_by TEXT NOT NULL,
  received_by TEXT,
  coc_items JSON NOT NULL,
  signature_image TEXT,
  status TEXT DEFAULT 'In Progress',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME,
  invoiced_at DATETIME,
  FOREIGN KEY (receiver_id) REFERENCES verified_receivers(id) ON DELETE SET NULL
);

-- 8. Reports
CREATE TABLE IF NOT EXISTS materials_out_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_month TEXT NOT NULL,
  generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  report_data JSON
);

-- 9. Product Bundles
CREATE TABLE IF NOT EXISTS product_bundles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  sku TEXT,
  description TEXT,
  is_active BOOLEAN DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 10. Bundle Items (Components)
CREATE TABLE IF NOT EXISTS bundle_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bundle_id INTEGER NOT NULL,
  stock_item_id INTEGER NOT NULL,
  quantity DECIMAL(10, 2) NOT NULL,
  FOREIGN KEY (bundle_id) REFERENCES product_bundles(id) ON DELETE CASCADE,
  FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
);

-- 11. Verified Receivers
CREATE TABLE IF NOT EXISTS verified_receivers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  contact_person TEXT,
  address TEXT,
  phone TEXT,
  notes TEXT,
  is_active BOOLEAN DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
