INSERT INTO roles (code,name,permissions) VALUES
('main_admin','Main Admin Account',JSON_ARRAY('*')),
('manager','Manager',JSON_ARRAY('dashboard.view','products.view','products.manage','customers.view','customers.manage','approvals.manage','reports.view','inventory.view')),
('cashier','Cashier',JSON_ARRAY('dashboard.view','sales.create','customers.view','payments.view','payments.create')),
('storekeeper','Storekeeper',JSON_ARRAY('dashboard.view','products.view','purchases.view','purchases.create','inventory.view','inventory.manage','imports.manage'));
INSERT INTO users (role_id,display_name,email,password_hash) SELECT id,'Main Admin Account','admin@supungroup.lk','$2y$10$KfzNYHQXcizemA6wjLEvW.OWkXci9ZoBZjnmSAwdQabX8wmRLhHoq' FROM roles WHERE code='main_admin';
INSERT INTO categories(name) VALUES ('Televisions'),('Refrigerators'),('Fans'),('Small Appliances'),('Accessories');
INSERT INTO brands(name) VALUES ('Samsung'),('LG'),('Sony'),('Panasonic'),('Other');
INSERT INTO customers(customer_code,name,customer_type,credit_enabled) VALUES ('WALK-IN','Walk-in Customer','both',0);
INSERT INTO expense_categories(name) VALUES ('Electricity'),('Rent'),('Salaries'),('Transport'),('Delivery'),('Repairs'),('Bank Charges'),('Office Expenses'),('Marketing'),('Other');
INSERT INTO accounts(name,account_type) VALUES ('Main Cash','cash'),('Primary Bank','bank');
INSERT INTO settings(setting_key,setting_value) VALUES ('business_name','Supun Group'),('currency','LKR'),('allow_negative_stock','0'),('costing_method','weighted_average'),('invoice_prefix','SALE');
