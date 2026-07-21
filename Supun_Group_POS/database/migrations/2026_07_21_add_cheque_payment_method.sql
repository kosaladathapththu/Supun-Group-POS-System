ALTER TABLE orders
  MODIFY payment_method ENUM('Cash','Card','QR','Bank Transfer','Cheque','Credit')
  NOT NULL DEFAULT 'Cash';
