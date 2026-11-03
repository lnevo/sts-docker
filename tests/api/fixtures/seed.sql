-- Synthetic dataset for the API tests (not a real layout). Applied once to a
-- freshly provisioned database to produce the committed backup file
-- `api_fixture`; see tests/api/README.md.
DELETE FROM cars; DELETE FROM car_codes; DELETE FROM car_orders; DELETE FROM commodities;
DELETE FROM jobs; DELETE FROM locations; DELETE FROM routing; DELETE FROM shipments;
DELETE FROM owners; DELETE FROM ownership; DELETE FROM empty_locations; DELETE FROM pool;
INSERT INTO routing (id, station, station_nbr, instructions, sort_seq, color1, color2) VALUES
 (1, 'Alpha Yard', 1, 'Main yard', 1, 0, 0),
 (2, 'Beta Siding', 2, 'Industrial siding', 2, 0, 0);
INSERT INTO locations (Id, code, station, track, spot, rpt_station, remarks, color) VALUES
 (1, 'ALPHA-1', 1, '1', '1', 'Alpha Yard', '', ''),
 (2, 'ALPHA-2', 1, '2', '1', 'Alpha Yard', '', ''),
 (3, 'BETA-1', 2, '1', '1', 'Beta Siding', '', ''),
 (4, 'BETA-2', 2, '2', '1', 'Beta Siding', '', '');
INSERT INTO car_codes (Id, code, description, remarks) VALUES (1, 'XM', 'Boxcar', ''), (2, 'GB', 'Gondola', '');
INSERT INTO commodities (Id, Code, Description, Remarks) VALUES (1, 'WIDGETS', 'Widgets', '');
INSERT INTO jobs (Id, name, description) VALUES (1, 'Local', 'Local job'), (2, 'Turn', 'Turn job');
INSERT INTO shipments (Id, code, description, consignment, car_code, loading_location, unloading_location,
  last_ship_date, min_interval, max_interval, min_amount, max_amount, special_instructions, remarks) VALUES
 (1, 'WIDGETS-OUT', 'Widgets to Beta', 1, 1, 1, 3, 0, 1, 3, 1, 2, '', '');
INSERT INTO cars (Id, reporting_marks, car_code_id, current_location_id, position, status, handled_by_job_id, remarks, load_count, home_location, RFID_code) VALUES
 (1, 'TST 1001', 1, 1, 1, 'Empty', 1, 'api-test', 0, 1, 'RFID1001'),
 (2, 'TST 1002', 1, 1, 2, 'Empty', 1, 'api-test', 0, 1, 'RFID1002'),
 (3, 'TST 1003', 1, 2, 1, 'Empty', 2, 'api-test', 0, 1, 'RFID1003'),
 (4, 'TST 1004', 1, 2, 2, 'Empty', 2, 'api-test', 0, 1, 'RFID1004'),
 (5, 'TST 1005', 1, 3, 1, 'Loading', 1, 'api-test', 1, 1, 'RFID1005'),
 (6, 'TST 1006', 1, 3, 2, 'Unloading', 1, 'api-test', 1, 1, 'RFID1006'),
 (7, 'TST 1007', 2, 4, 1, 'Loaded', 2, 'api-test', 1, 2, 'RFID1007'),
 (8, 'TST 1008', 2, 4, 2, 'Empty', 2, 'api-test', 0, 2, 'RFID1008'),
 (9, 'TST 1009', 1, 1, 3, 'Empty', 1, 'api-test', 0, 1, 'RFID1009'),
 (10, 'TST 1010', 1, 1, 4, 'Empty', 1, 'api-test', 0, 1, 'RFID1010'),
 (11, 'TST 1011', 1, 1, 5, 'Empty', 1, 'api-test', 0, 1, 'RFID1011'),
 (12, 'TST 1012', 1, 1, 6, 'Empty', 1, 'api-test', 0, 1, 'RFID1012');
INSERT INTO car_orders (waybill_number, shipment, car) VALUES
 ('000-001', 1, 5), ('000-002', 1, 6), ('000-003', 1, 7);
INSERT INTO owners (id, name, remarks) VALUES (1, 'Test Owner', '');
INSERT INTO ownership (car_id, owner_id, on_off_rr) VALUES (1, 1, 'On');
