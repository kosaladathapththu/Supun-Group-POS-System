CREATE DATABASE IF NOT EXISTS supun_group_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE supun_group_pos;

SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS restaurant_tables;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE users (
  user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','manager','cashier') NOT NULL DEFAULT 'cashier',
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
  category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL UNIQUE,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
  product_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  sku VARCHAR(60) NULL UNIQUE,
  barcode VARCHAR(80) NULL UNIQUE,
  product_name VARCHAR(180) NOT NULL,
  unit VARCHAR(30) NOT NULL DEFAULT 'pcs',
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  price DECIMAL(12,2) NOT NULL,
  wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  wholesale_min_qty INT UNSIGNED NOT NULL DEFAULT 10,
  stock_qty DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  reorder_level DECIMAL(12,3) NOT NULL DEFAULT 5.000,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(category_id)
) ENGINE=InnoDB;

/* Kept for backward compatibility with older report/receipt code. */
CREATE TABLE restaurant_tables (
  table_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_name VARCHAR(50) NOT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE orders (
  order_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(30) NULL UNIQUE,
  table_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  order_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  customer_name VARCHAR(150) NULL,
  customer_phone VARCHAR(30) NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  service_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  packaging_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_status ENUM('pending','paid','partial','cancelled') NOT NULL DEFAULT 'pending',
  order_status ENUM('open','paid','cancelled') NOT NULL DEFAULT 'open',
  payment_method ENUM('Cash','Card','QR','Bank Transfer','Credit') NOT NULL DEFAULT 'Cash',
  cash_given DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sync_status TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(user_id),
  CONSTRAINT fk_orders_legacy_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(table_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE order_items (
  order_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL,
  custom_item_name VARCHAR(180) NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  item_type ENUM('product','manual') NOT NULL DEFAULT 'product',
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE expenses (
  expense_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_method VARCHAR(40) NOT NULL DEFAULT 'Cash',
  note TEXT NULL,
  added_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expense_user FOREIGN KEY (added_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO users (full_name,username,password,role,status) VALUES
('System Administrator','admin','$2y$10$skvzEZX78q3VcbFNASq73uT9yzC8pMsbkmqXsGTvdMxBnCqy4Qn.q','admin',1),
('Store Manager','manager','$2y$10$skvzEZX78q3VcbFNASq73uT9yzC8pMsbkmqXsGTvdMxBnCqy4Qn.q','manager',1),
('Shop Cashier','cashier','$2y$10$skvzEZX78q3VcbFNASq73uT9yzC8pMsbkmqXsGTvdMxBnCqy4Qn.q','cashier',1);

INSERT INTO categories (category_name) VALUES
('Air Conditioners'),('Refrigerators'),('Fans'),('Televisions'),('Washing Machines'),('Small Appliances');

INSERT INTO products (category_id,sku,barcode,product_name,unit,cost_price,price,wholesale_price,wholesale_min_qty,stock_qty,reorder_level) VALUES
(1,'AC-INV-12000','4791000000010','Inverter Air Conditioner 12000 BTU','unit',135000,159900,149500,2,8,2),
(1,'AC-INV-18000','4791000000027','Inverter Air Conditioner 18000 BTU','unit',195000,229900,215000,2,5,2),
(2,'REF-240L-001','4791000000034','Double Door Refrigerator 240L','unit',142000,169900,158000,2,7,2),
(2,'REF-320L-001','4791000000041','Inverter Refrigerator 320L','unit',225000,259900,244000,2,4,1),
(3,'FAN-CEIL-056','4791000000058','Ceiling Fan 56 Inch','unit',14500,17900,16250,5,24,5),
(3,'FAN-STAND-016','4791000000065','Stand Fan 16 Inch','unit',16800,20900,18900,5,18,4),
(4,'TV-SMART-043','4791000000072','43 Inch Smart LED Television','unit',89000,104900,97900,2,6,2),
(5,'WM-FRONT-008','4791000000089','Front Load Washing Machine 8kg','unit',155000,184900,172000,2,5,1),
(6,'APP-RICE-18L','4791000000096','Electric Rice Cooker 1.8L','unit',7200,8950,8100,6,20,5),
(6,'APP-BLEND-15L','4791000000102','Multi-function Blender 1.5L','unit',9800,12450,11200,6,15,4);

DELIMITER $$
CREATE TRIGGER trg_order_item_stock_after_insert AFTER INSERT ON order_items
FOR EACH ROW BEGIN
  IF NEW.product_id IS NOT NULL THEN
    UPDATE products SET stock_qty = stock_qty - NEW.quantity WHERE product_id = NEW.product_id;
  END IF;
END$$
CREATE TRIGGER trg_order_item_stock_after_update AFTER UPDATE ON order_items
FOR EACH ROW BEGIN
  IF OLD.product_id IS NOT NULL THEN
    UPDATE products SET stock_qty = stock_qty + OLD.quantity WHERE product_id = OLD.product_id;
  END IF;
  IF NEW.product_id IS NOT NULL THEN
    UPDATE products SET stock_qty = stock_qty - NEW.quantity WHERE product_id = NEW.product_id;
  END IF;
END$$
CREATE TRIGGER trg_order_item_stock_after_delete AFTER DELETE ON order_items
FOR EACH ROW BEGIN
  IF OLD.product_id IS NOT NULL THEN
    UPDATE products SET stock_qty = stock_qty + OLD.quantity WHERE product_id = OLD.product_id;
  END IF;
END$$
DELIMITER ;
