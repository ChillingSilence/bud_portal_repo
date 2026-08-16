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
  cancelled_at DATETIME,
  cancel_reason TEXT,
  FOREIGN KEY (receiver_id) REFERENCES verified_receivers(id) ON DELETE SET NULL
);

-- 12. Products (verified medicinal cannabis products — the S29 constants)
CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  inn_generic TEXT,
  dose_form TEXT,
  pack_size TEXT,
  strength TEXT,
  is_active BOOLEAN DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 13. S29 Imports (one row per uploaded monthly pharmacy file)
CREATE TABLE IF NOT EXISTS s29_imports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT,
  pharmacy TEXT NOT NULL,
  default_product_id INTEGER,
  row_count INTEGER DEFAULT 0,
  total_quantity DECIMAL(10, 2) DEFAULT 0,
  imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (default_product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- 14. S29 Supplies (parsed dispensing rows — Section 29 record fields only)
CREATE TABLE IF NOT EXISTS s29_supplies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  import_id INTEGER NOT NULL,
  supplied_at DATETIME,
  supply_month TEXT,
  prescriber TEXT,
  prescriber_facility TEXT,
  patient TEXT,
  med_name TEXT,
  med_plu TEXT,
  quantity DECIMAL(10, 2) NOT NULL DEFAULT 0,
  raw_quantity DECIMAL(10, 2),
  product_id INTEGER,
  pharmacy TEXT,
  FOREIGN KEY (import_id) REFERENCES s29_imports(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- 15. Destruction Register (controlled substance destruction records for MCA)
CREATE TABLE IF NOT EXISTS destruction_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  stock_item_id INTEGER,
  item_name TEXT NOT NULL,
  batch TEXT,
  quantity DECIMAL(10, 2) NOT NULL,
  unit TEXT,
  reason TEXT NOT NULL,
  method TEXT NOT NULL,
  destroyed_by TEXT NOT NULL,
  witness TEXT,
  witness_signature TEXT,
  notes TEXT,
  destroyed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL
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
