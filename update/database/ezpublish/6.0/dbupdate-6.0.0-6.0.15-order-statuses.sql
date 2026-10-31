--
-- Exponential 6.0.15, Oracle: the order statuses of a whole order lifecycle, in ezorder_status.
--
-- Internal statuses (below 1000) for payment (Awaiting payment, Paid, Payment failed),
-- fulfillment (On hold, Backordered, Packed, Shipped, Ready for pickup), the end of an order
-- (Completed, Cancelled) and what can follow it (Return requested, Returned, Partially
-- refunded, Refunded). A status is only added when no row has its status_id yet, and its id
-- comes from the table's sequence, so a custom status that already uses one of those ids
-- keeps it, and the file can run again.
--
-- Run with SQL*Plus or SQLcl as the schema owner.
--

INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Awaiting payment', 4 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 4 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Paid', 5 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 5 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Payment failed', 6 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 6 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'On hold', 7 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 7 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Backordered', 8 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 8 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Packed', 9 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 9 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Shipped', 10 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 10 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Ready for pickup', 11 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 11 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Completed', 12 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 12 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Cancelled', 13 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 13 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Return requested', 14 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 14 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Returned', 15 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 15 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Partially refunded', 16 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 16 );
INSERT INTO ezorder_status ( is_active, name, status_id )
  SELECT 1, 'Refunded', 17 FROM dual WHERE NOT EXISTS ( SELECT 1 FROM ezorder_status WHERE status_id = 17 );
COMMIT;
