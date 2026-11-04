-- Synthetic railroad for the browser regression checks (not a real layout).
-- Applied once to a freshly provisioned database to produce the committed
-- backup `browser_fixture`; see tests/browser/README.md. Three stations, six
-- locations, three car codes and commodities, two jobs with steps and pickup
-- criteria, six shipments, thirty cars.
DELETE FROM cars; DELETE FROM car_codes; DELETE FROM car_orders; DELETE FROM commodities;
DELETE FROM jobs; DELETE FROM locations; DELETE FROM routing; DELETE FROM shipments;
DELETE FROM owners; DELETE FROM ownership; DELETE FROM empty_locations; DELETE FROM pool;
DROP TABLE IF EXISTS pu_criteria;
DROP TABLE IF EXISTS LOC;
DROP TABLE IF EXISTS TURN;
CREATE TABLE pu_criteria (id int NOT NULL AUTO_INCREMENT, job_id varchar(64) DEFAULT NULL,
  step_nbr int DEFAULT NULL, car_status varchar(256) DEFAULT NULL, commodity_id int DEFAULT NULL,
  car_code_id int DEFAULT NULL, dest_station_id int DEFAULT NULL, PRIMARY KEY (id));
CREATE TABLE LOC (step_number int NOT NULL, station int DEFAULT NULL, pickup char(1) DEFAULT NULL,
  setout char(1) DEFAULT NULL, remarks varchar(256) DEFAULT NULL, PRIMARY KEY (step_number));
CREATE TABLE TURN (step_number int NOT NULL, station int DEFAULT NULL, pickup char(1) DEFAULT NULL,
  setout char(1) DEFAULT NULL, remarks varchar(256) DEFAULT NULL, PRIMARY KEY (step_number));
INSERT INTO routing (id, station, station_nbr, instructions, sort_seq, color1, color2) VALUES
 (1, 'Alpha Yard', 1, 'Main yard', 1, 0, 0),
 (2, 'Beta Siding', 2, 'Industrial siding', 2, 0, 0),
 (3, 'Gamma Works', 3, 'Works and loading dock', 3, 0, 0);
INSERT INTO locations (Id, code, station, track, spot, rpt_station, remarks, color) VALUES
 (1, 'ALPHA-1', 1, '1', '1', 'Alpha Yard', '', ''),
 (2, 'ALPHA-2', 1, '2', '1', 'Alpha Yard', '', ''),
 (3, 'BETA-1', 2, '1', '1', 'Beta Siding', '', ''),
 (4, 'BETA-2', 2, '2', '1', 'Beta Siding', '', ''),
 (5, 'GAMMA-1', 3, '1', '1', 'Gamma Works', '', ''),
 (6, 'GAMMA-2', 3, '2', '1', 'Gamma Works', '', '');
INSERT INTO car_codes (Id, code, description, remarks) VALUES
 (1, 'XM', 'Boxcar', ''), (2, 'GB', 'Gondola', ''), (3, 'HT', 'Hopper', '');
INSERT INTO commodities (Id, Code, Description, Remarks) VALUES
 (1, 'WIDGETS', 'Widgets', ''), (2, 'GRAVEL', 'Gravel', ''), (3, 'COAL', 'Coal', '');
INSERT INTO jobs (Id, name, description) VALUES
 (1, 'LOC', 'Local switcher between Alpha and Beta'), (2, 'TURN', 'Turn from Alpha to Gamma and back');
INSERT INTO LOC VALUES (10, 1, 'T', 'T', 'Alpha Yard: pick up and set out'),
 (20, 2, 'T', 'T', 'Beta Siding: pick up and set out');
INSERT INTO TURN VALUES (10, 2, 'T', 'T', 'Beta Siding: pick up and set out'),
 (20, 3, 'T', 'T', 'Gamma Works: pick up and set out'),
 (30, 1, 'T', 'F', 'Alpha Yard: pick up');
INSERT INTO pu_criteria (job_id, step_nbr, car_status, commodity_id, car_code_id, dest_station_id) VALUES
 ('LOC', 10, '', '', '', 2), ('LOC', 20, '', '', '', 1),
 ('TURN', 10, '', '', '', 3), ('TURN', 20, '', '', '', 2), ('TURN', 30, '', '', '', 1);
INSERT INTO shipments (Id, code, description, consignment, car_code, loading_location, unloading_location,
  last_ship_date, min_interval, max_interval, min_amount, max_amount, special_instructions, remarks) VALUES
 (1, 'WIDGETS-AB', 'Widgets Alpha to Beta', 1, 1, 1, 3, 0, 1, 1, 1, 2, '', ''),
 (2, 'WIDGETS-BA', 'Widgets Beta to Alpha', 1, 1, 4, 2, 0, 1, 1, 1, 2, '', ''),
 (3, 'GRAVEL-BG', 'Gravel Beta to Gamma', 2, 2, 3, 5, 0, 1, 1, 1, 2, '', ''),
 (4, 'GRAVEL-GB', 'Gravel Gamma to Beta', 2, 2, 6, 4, 0, 1, 1, 1, 2, '', ''),
 (5, 'COAL-GA', 'Coal Gamma to Alpha', 3, 3, 5, 1, 0, 1, 1, 1, 2, '', ''),
 (6, 'COAL-AG', 'Coal Alpha to Gamma', 3, 3, 2, 6, 0, 1, 1, 1, 2, '', '');
INSERT INTO empty_locations (shipment, priority, location) VALUES
 (1, 1, 1), (2, 1, 4), (3, 1, 3), (4, 1, 6), (5, 1, 5), (6, 1, 2);
INSERT INTO cars (Id, reporting_marks, car_code_id, current_location_id, position, status, handled_by_job_id, remarks, load_count, home_location, RFID_code) VALUES
 (1, 'TST 1001', 1, 1, 1, 'Empty', 0, 'browser-test', 0, 1, 'RFID1001'),
 (2, 'TST 1002', 1, 1, 2, 'Empty', 0, 'browser-test', 0, 1, 'RFID1002'),
 (3, 'TST 1003', 1, 1, 3, 'Empty', 0, 'browser-test', 0, 1, 'RFID1003'),
 (4, 'TST 1004', 1, 1, 4, 'Empty', 0, 'browser-test', 0, 1, 'RFID1004'),
 (5, 'TST 1005', 1, 4, 1, 'Empty', 0, 'browser-test', 0, 4, 'RFID1005'),
 (6, 'TST 1006', 1, 4, 2, 'Empty', 0, 'browser-test', 0, 4, 'RFID1006'),
 (7, 'TST 1007', 1, 4, 3, 'Empty', 0, 'browser-test', 0, 4, 'RFID1007'),
 (8, 'TST 1008', 1, 2, 1, 'Empty', 0, 'browser-test', 0, 2, 'RFID1008'),
 (9, 'TST 1009', 1, 2, 2, 'Empty', 0, 'browser-test', 0, 2, 'RFID1009'),
 (10, 'TST 1010', 1, 2, 3, 'Empty', 0, 'browser-test', 0, 2, 'RFID1010'),
 (11, 'TST 1101', 2, 3, 1, 'Empty', 0, 'browser-test', 0, 3, 'RFID1101'),
 (12, 'TST 1102', 2, 3, 2, 'Empty', 0, 'browser-test', 0, 3, 'RFID1102'),
 (13, 'TST 1103', 2, 3, 3, 'Empty', 0, 'browser-test', 0, 3, 'RFID1103'),
 (14, 'TST 1104', 2, 3, 4, 'Empty', 0, 'browser-test', 0, 3, 'RFID1104'),
 (15, 'TST 1105', 2, 6, 1, 'Empty', 0, 'browser-test', 0, 6, 'RFID1105'),
 (16, 'TST 1106', 2, 6, 2, 'Empty', 0, 'browser-test', 0, 6, 'RFID1106'),
 (17, 'TST 1107', 2, 6, 3, 'Empty', 0, 'browser-test', 0, 6, 'RFID1107'),
 (18, 'TST 1108', 2, 4, 4, 'Empty', 0, 'browser-test', 0, 4, 'RFID1108'),
 (19, 'TST 1109', 2, 4, 5, 'Empty', 0, 'browser-test', 0, 4, 'RFID1109'),
 (20, 'TST 1110', 2, 3, 5, 'Empty', 0, 'browser-test', 0, 3, 'RFID1110'),
 (21, 'TST 1201', 3, 5, 1, 'Empty', 0, 'browser-test', 0, 5, 'RFID1201'),
 (22, 'TST 1202', 3, 5, 2, 'Empty', 0, 'browser-test', 0, 5, 'RFID1202'),
 (23, 'TST 1203', 3, 5, 3, 'Empty', 0, 'browser-test', 0, 5, 'RFID1203'),
 (24, 'TST 1204', 3, 5, 4, 'Empty', 0, 'browser-test', 0, 5, 'RFID1204'),
 (25, 'TST 1205', 3, 2, 4, 'Empty', 0, 'browser-test', 0, 2, 'RFID1205'),
 (26, 'TST 1206', 3, 2, 5, 'Empty', 0, 'browser-test', 0, 2, 'RFID1206'),
 (27, 'TST 1207', 3, 2, 6, 'Empty', 0, 'browser-test', 0, 2, 'RFID1207'),
 (28, 'TST 1208', 3, 1, 5, 'Empty', 0, 'browser-test', 0, 1, 'RFID1208'),
 (29, 'TST 1209', 3, 5, 5, 'Empty', 0, 'browser-test', 0, 5, 'RFID1209'),
 (30, 'TST 1210', 3, 5, 6, 'Empty', 0, 'browser-test', 0, 5, 'RFID1210');
INSERT INTO pool (car_id, shipment_id) VALUES (3, 2), (4, 2);
INSERT INTO owners (id, name, remarks) VALUES (1, 'Test Owner', '');
INSERT INTO ownership (car_id, owner_id, on_off_rr) VALUES (1, 1, 'On');
