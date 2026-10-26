-- ============================================================================
--  CTMS CONSULTANCY PLATFORM — STANDARDS LIBRARY SEED
--
--  This is the system's knowledge of laboratory testing. It is used to:
--    1. SUGGEST a test method when a technician names a sample type
--    2. CHECK entered readings against physically plausible ranges
--    3. SUGGEST PASS/FAIL against known material grade requirements
--
--  IMPORTANT PHILOSOPHY (decided by the laboratory):
--    The instruments in this lab are not always calibrated and many are
--    uncalibrated by necessity, so values outside a published range are NOT
--    automatically wrong. Everything here produces a WARNING and a SUGGESTION.
--    Nothing blocks a technician. Every override is recorded.
--
--  Revisions: standards are revised periodically. The `revision` column exists
--  so that a report can state exactly which edition was used. Where an exact
--  year is uncertain it is left NULL rather than guessed.
-- ============================================================================

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
--  1. STANDARDS
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO c_standards (code, title, body, revision, scope_summary) VALUES
('ASTM E8/E8M','Standard Test Methods for Tension Testing of Metallic Materials','ASTM','2022','Determines tensile properties of metallic materials: yield strength, tensile strength, elongation and reduction of area. The base method for all metal tensile work.'),
('ASTM A370','Standard Test Methods and Definitions for Mechanical Testing of Steel Products','ASTM','2022','Mechanical testing of steel products: tension, bend, hardness and impact. Commonly cited on steel mill certificates and rebar reports.'),
('ASTM A615/A615M','Standard Specification for Deformed and Plain Carbon-Steel Bars for Concrete Reinforcement','ASTM','2022','Covers deformed and plain carbon steel bars for concrete reinforcement. Defines minimum yield strength per grade (Grade 40, 60, 75, 80).'),
('ASTM A36/A36M','Standard Specification for Carbon Structural Steel','ASTM','2019','Structural carbon steel shapes, plates and bars. Minimum yield 250 MPa and tensile 400-550 MPa for the common thickness range.'),
('ASTM A572/A572M','Standard Specification for High-Strength Low-Alloy Columbium-Vanadium Structural Steel','ASTM','2021','High-strength low-alloy structural steel in grades 42, 50, 55, 60 and 65.'),
('ASTM A706/A706M','Standard Specification for Deformed and Plain Low-Alloy Steel Bars for Concrete Reinforcement','ASTM','2022','Low-alloy deformed bars for concrete reinforcement, used where weldability or controlled strength is required.'),
('ASTM E10','Standard Test Method for Brinell Hardness of Metallic Materials','ASTM','2023','Brinell hardness using a carbide ball indenter. Reported as HBW. Suited to coarse or inhomogeneous material such as castings and forgings.'),
('ASTM E18','Standard Test Methods for Rockwell Hardness of Metallic Materials','ASTM','2022','Rockwell hardness using diamond cone or ball indenter. Reported as HRC, HRB, HRA etc. The usual test where a fast, cheap hardness reading is needed.'),
('ASTM E384','Standard Test Method for Microindentation Hardness of Materials','ASTM','2023','Vickers and Knoop microindentation hardness. Used for case depth, weld zones and small or thin features.'),
('ASTM E92','Standard Test Methods for Vickers Hardness and Knoop Hardness of Metallic Materials','ASTM','2023','Macro Vickers hardness. Reported as HV.'),
('ASTM E23','Standard Test Methods for Notched Bar Impact Testing of Metallic Materials','ASTM','2023','Charpy and Izod impact testing. Reported as absorbed energy in joules, with specimen dimensions, notch type and test temperature.'),
('ASTM E415','Standard Test Method for Analysis of Carbon and Low-Alloy Steel by Spark Atomic Emission Spectrometry','ASTM','2022','Optical emission spectrometry (OES) analysis of carbon and low-alloy steels. Covers the usual element set C, Si, Mn, P, S, Cr, Ni, Mo, Cu, Al.'),
('ASTM E1086','Standard Test Method for Analysis of Austenitic Stainless Steel by Spark Atomic Emission Spectrometry','ASTM','2022','OES analysis of austenitic stainless steel, where nickel and chromium are the significant elements.'),
('ASTM E1621','Standard Guide for Elemental Analysis by Wavelength Dispersive X-Ray Fluorescence Spectrometry','ASTM','2022','Guide to XRF analysis, including sample preparation and the meaning of reported uncertainty.'),
('ASTM E572','Standard Test Method for Analysis of Stainless and Alloy Steels by Wavelength Dispersive X-Ray Fluorescence Spectrometry','ASTM','2022','XRF analysis of stainless and alloy steels. Common method for the chemical composition tables printed on consultancy reports.'),
('ASTM E322','Standard Test Method for Analysis of Low-Alloy Steels and Cast Irons by Wavelength Dispersive X-Ray Fluorescence Spectrometry','ASTM','2022','XRF analysis of low-alloy steels and cast irons.'),
('ASTM B117','Standard Practice for Operating Salt Spray (Fog) Apparatus','ASTM','2019','Accelerated corrosion testing in a salt fog chamber. Reports exposure hours and observed corrosion, or a calculated corrosion rate.'),
('ASTM G1','Standard Practice for Preparing, Cleaning, and Evaluating Corrosion Test Specimens','ASTM','2017','How to clean and evaluate corroded specimens and calculate corrosion rate from mass loss.'),
('ASTM E3','Standard Guide for Preparation of Metallographic Specimens','ASTM','2019','Sectioning, mounting, grinding and polishing of specimens for microstructural examination.'),
('ASTM E407','Standard Practice for Microetching Metals and Alloys','ASTM','2015','Etchants and etching practice for revealing microstructure. Referenced by the usual 500X micrographs.'),
('ASTM E112','Standard Test Methods for Determining Average Grain Size','ASTM','2021','Measurement of average grain size, usually reported in micrometres or as an ASTM grain size number.'),
('ASTM E140','Standard Hardness Conversion Tables for Metals','ASTM','2022','Conversion tables between Brinell, Vickers, Rockwell and tensile strength. The authority normally cited when a report prints a hardness conversion.'),
('ASTM E290','Standard Test Methods for Bend Testing of Material for Ductility','ASTM','2022','Semi-guided and guided bend tests. Reported as pass/fail with the mandrel or bend angle.'),
('ISO 6892-1','Metallic materials — Tensile testing — Part 1: Method of test at room temperature','ISO','2019','The international equivalent of ASTM E8. Cited when a client requests ISO rather than ASTM.'),
('ISO 6506-1','Metallic materials — Brinell hardness test — Part 1: Test method','ISO','2014','International Brinell method, reported as HBW.'),
('ISO 6508-1','Metallic materials — Rockwell hardness test — Part 1: Test method','ISO','2016','International Rockwell method.'),
('ISO 148-1','Metallic materials — Charpy pendulum impact test — Part 1: Test method','ISO','2016','International Charpent impact method.'),
('ISO 9227','Corrosion tests in artificial atmospheres — Salt spray tests','ISO','2022','International salt spray method, equivalent in purpose to ASTM B117.'),
('ASTM C109/C109M','Standard Test Method for Compressive Strength of Hydraulic Cement Mortars','ASTM','2021','Compressive strength of cement mortar cubes. Relevant when cement or masonry cement is submitted.'),
('ASTM C187','Standard Test Method for Amount of Water Required for Normal Consistency of Hydraulic Cement Paste','ASTM','2016','Normal consistency of cement paste. This is the "Normal Consistency (%)" column seen on masonry cement reports.'),
('ASTM C348','Standard Test Method for Flexural Strength of Hydraulic-Cement Mortars','ASTM','2021','Flexural strength of mortar prisms, reported as modulus of rupture.'),
('ASTM D638','Standard Test Method for Tensile Properties of Plastics','ASTM','2022','Tensile properties of unreinforced and reinforced plastics, including the polystyrene and polymer sheet work.'),
('ASTM D790','Standard Test Methods for Flexural Properties of Unreinforced and Reinforced Plastics','ASTM','2017','Flexural (bending) properties of plastics, reported as flexural modulus and strength.'),
('ASTM D2240','Standard Test Method for Rubber Property — Durometer Hardness','ASTM','2015','Durometer hardness of rubber and soft plastics.'),
('ASTM D792','Standard Test Methods for Density and Specific Gravity (Relative Density) of Plastics by Displacement','ASTM','2020','Density of plastics by water displacement.'),
('Example Client PS:3759','Pakistan Standard — Electric Hand Free','Example Client',NULL,'Pakistan standard for hand-free (handsfree) appliances, cited on the Example Client electronics reports.'),
('Example Client PS:4962','Pakistan Standard — Electric Gas Stoves','Example Client',NULL,'Pakistan standard for gas stoves, cited on Example Client appliance reports.');

-- ─────────────────────────────────────────────────────────────────────────────
--  2. TEST TYPES  (result_style drives the shape of the results table)
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO c_test_types (code, name, category, result_style, report_title, sort_order, default_standard_id) VALUES
('TENSILE','Tensile Test (UTM)','mechanical','single_value','TENSILE TEST (UTM)',10,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E8/E8M' AND revision='2022') x)),
('HARDNESS_BRINELL','Brinell Hardness (HBW)','hardness','multi_reading','BRINELL HARDNESS (HBW)',20,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E10' AND revision='2023') x)),
('HARDNESS_ROCKWELL','Rockwell Hardness (HRC/HRB)','hardness','multi_reading','ROCKWELL HARDNESS',30,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E18' AND revision='2022') x)),
('HARDNESS_VICKERS','Vickers Hardness (HV)','hardness','single_value','VICKERS HARDNESS TEST',40,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E92' AND revision='2023') x)),
('CASE_DEPTH','Case Depth Profile (HV)','hardness','depth_profile','HARDNESS TEST',45,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E384' AND revision='2023') x)),
('IMPACT','Impact Test (Charpy)','impact','single_value','IMPACT TEST REPORT',50,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E23' AND revision='2023') x)),
('CHEM_OES','Chemical Composition (OES)','chemical','composition_multi','CHEMICAL COMPOSITION (OES)',60,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E415' AND revision='2022') x)),
('CHEM_XRF','Chemical Composition (XRF)','chemical','composition_multi','CHEMICAL COMPOSITION (XRF)',65,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E572' AND revision='2022') x)),
('CORROSION','Corrosion Test (Salt Spray)','corrosion','single_value','CORROSION TEST REPORT',70,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM B117' AND revision='2019') x)),
('BEND','Bend Test','mechanical','parameter_table','BEND TEST',80,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E290' AND revision='2022') x)),
('MICROSTRUCTURE','Microstructure Examination','metallography','parameter_table','MICROSTRUCTURE',90,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E3' AND revision='2019') x)),
('GRAIN_SIZE','Average Grain Size','metallography','single_value','GRAIN SIZE DETERMINATION',95,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM E112' AND revision='2021') x)),
('CEMENT_CONSISTENCY','Cement Normal Consistency','other','single_value','NORMAL CONSISTENCY',100,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM C187' AND revision='2016') x)),
('CEMENT_FLEXURAL','Cement Mortar Flexural Strength','other','single_value','FLEXURAL STRENGTH',105,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM C348' AND revision='2021') x)),
('PLASTIC_TENSILE','Plastic Tensile','mechanical','single_value','TENSILE PROPERTIES (PLASTIC)',110,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM D638' AND revision='2022') x)),
('PLASTIC_FLEXURAL','Plastic Flexural','mechanical','single_value','FLEXURAL PROPERTIES (PLASTIC)',115,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM D790' AND revision='2017') x)),
('DENSITY','Density','other','single_value','DENSITY',120,
    (SELECT id FROM (SELECT id FROM c_standards WHERE code='ASTM D792' AND revision='2020') x)),
('FLEXURAL_STRENGTH','Flexural Strength (Mortar/Stone)','other','single_value','FLEXURAL STRENGTH',108,NULL),
('COMPRESSIVE_STRENGTH','Compressive Strength','other','single_value','COMPRESSIVE STRENGTH',109,NULL),
('VISUAL','Visual Inspection','other','parameter_table','VISUAL INSPECTION',130,NULL);

-- ─────────────────────────────────────────────────────────────────────────────
--  3. MEASUREMENTS  (the definitive list of measurable quantities)
--     accepted_units is JSON: unit => multiplier to reach the canonical unit
--     marker_json lists legitimate non-numeric entries, so '< 0.001' is never
--       silently turned into zero.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO c_measurements
 (code, name, symbol, canonical_unit, accepted_units, value_kind, marker_json, decimals, is_analyte, sort_order) VALUES
-- tensile
('YS','Yield Strength / Proof Strength','ReH','MPa','{"MPa":1,"N/mm2":1,"N/mm²":1,"ksi":6.894757,"kgf/mm2":9.80665}','numeric',NULL,2,0,10),
('UTS','Ultimate Tensile Strength','Rm','MPa','{"MPa":1,"N/mm2":1,"N/mm²":1,"ksi":6.894757,"kgf/mm2":9.80665}','numeric',NULL,2,0,20),
('ELONGATION','Elongation','A','%','{"%":1,"percent":1}','numeric',NULL,2,0,30),
('REDUCTION_AREA','Reduction of Area','Z','%','{"%":1}','numeric',NULL,2,0,35),
('YIELD_LOAD','Yield / Proof Load','FeH','kN','{"kN":1,"KN":1,"N":0.001,"kgf":0.00980665,"lbf":0.004448222}','numeric',NULL,3,0,40),
('MAX_LOAD','Maximum Load','Fm','kN','{"kN":1,"KN":1,"N":0.001,"kgf":0.00980665,"lbf":0.004448222}','numeric',NULL,3,0,50),
('DIM_DIAMETER','Diameter','d','mm','{"mm":1,"cm":10,"in":25.4}','numeric',NULL,2,0,60),
('DIM_WIDTH','Width','w','mm','{"mm":1,"cm":10,"in":25.4}','numeric',NULL,2,0,70),
('DIM_THICKNESS','Thickness','t','mm','{"mm":1,"cm":10,"in":25.4}','numeric',NULL,2,0,80),
('GAUGE_LENGTH','Gauge Length','L0','mm','{"mm":1,"cm":10,"in":25.4}','numeric',NULL,2,0,90),
('CROSS_SECTION_AREA','Cross-Section Area','S0','mm2','{"mm2":1,"mm²":1,"cm2":100,"in2":645.16}','numeric',NULL,3,0,100),
('SPECIMEN_MASS','Specimen Mass','m','kg','{"kg":1,"g":0.001,"lb":0.45359237}','numeric',NULL,3,0,110),
-- hardness
('HBW','Brinell Hardness (HBW)','HBW','HBW','{"HBW":1,"HB":1}','numeric',NULL,2,0,200),
('HBW_MEAN','Brinell Hardness Mean','HBW','HBW','{"HBW":1}','numeric',NULL,2,0,210),
('HRC','Rockwell Hardness (HRC)','HRC','HRC','{"HRC":1}','numeric',NULL,2,0,220),
('HRC_MEAN','Rockwell Hardness Mean','HRC','HRC','{"HRC":1}','numeric',NULL,2,0,230),
('HRB','Rockwell Hardness (HRB)','HRB','HRB','{"HRB":1}','numeric',NULL,2,0,240),
('HRB_MEAN','Rockwell Hardness Mean','HRB','HRB','{"HRB":1}','numeric',NULL,2,0,250),
('HV','Vickers Hardness','HV','HV','{"HV":1,"HV1":1,"HV10":1}','numeric',NULL,1,0,260),
('HMV','Micro-Vickers Hardness (HMV)','HMV','HMV','{"HMV":1,"HV":1}','numeric',NULL,2,0,270),
('HMV_MEAN','Micro-Vickers Mean','HMV','HMV','{"HMV":1}','numeric',NULL,2,0,280),
('CASE_DEPTH_DISTANCE','Distance from Surface','x','mm','{"mm":1,"cm":10,"in":25.4,"µm":0.001,"um":0.001}','numeric',NULL,2,0,290),
('CASE_DEPTH','Case Depth','CHD','mm','{"mm":1,"µm":0.001,"um":0.001}','numeric',NULL,3,0,300),
-- impact
('IMPACT_ENERGY','Absorbed Energy','KV','J','{"J":1,"joule":1,"ft-lb":1.355818}','numeric',NULL,2,0,400),
('IMPACT_TEMPERATURE','Test Temperature','T','°C','{"°C":1,"C":1,"°F":0.5556}','numeric_or_text',
   '{"Room Temp.":"room_temperature","Room Temp":"room_temperature","RT":"room_temperature","Ambient":"room_temperature"}',1,0,410),
('SPECIMEN_SIZE','Specimen Dimensions','-','mm','{}','text',NULL,0,0,420),
-- corrosion
('CORROSION_RATE','Corrosion Rate','-','mm/year','{"mm/year":1,"mpy":0.0254}','numeric',NULL,3,0,500),
('SALT_SPRAY_HOURS','Exposure Duration','-','h','{"h":1,"hours":1,"hr":1}','numeric',NULL,0,0,510),
-- chemical elements (canonical unit = weight percent)
('EL_FE','Iron (Fe)','Fe','%','{"%":1,"wt%":1}','numeric_or_text',
   '{"Balance":"remainder","Bal.":"remainder","Bal":"remainder","Rem":"remainder"}',4,1,600),
('EL_C','Carbon (C)','C','%','{"%":1}','numeric_or_text',
   '{"< 0.001":"below_detection","<0.001":"below_detection","Max 0.23":"spec_max","Mini 0.40":"spec_min","Max 0.40":"spec_max","Max 0.1":"spec_max"}',4,1,610),
('EL_SI','Silicon (Si)','Si','%','{"%":1}','numeric_or_text',NULL,4,1,620),
('EL_MN','Manganese (Mn)','Mn','%','{"%":1}','numeric_or_text',NULL,4,1,630),
('EL_P','Phosphorus (P)','P','%','{"%":1}','numeric_or_text',NULL,4,1,640),
('EL_S','Sulfur (S)','S','%','{"%":1}','numeric_or_text',NULL,4,1,650),
('EL_CR','Chromium (Cr)','Cr','%','{"%":1}','numeric_or_text',NULL,4,1,660),
('EL_NI','Nickel (Ni)','Ni','%','{"%":1}','numeric_or_text',NULL,4,1,670),
('EL_MO','Molybdenum (Mo)','Mo','%','{"%":1}','numeric_or_text',
   '{"< 0.001":"below_detection","<0.001":"below_detection"}',4,1,680),
('EL_CU','Copper (Cu)','Cu','%','{"%":1}','numeric_or_text',NULL,4,1,690),
('EL_AL','Aluminium (Al)','Al','%','{"%":1}','numeric_or_text',NULL,4,1,700),
('EL_TI','Titanium (Ti)','Ti','%','{"%":1}','numeric_or_text',NULL,4,1,710),
('EL_NB','Niobium (Nb)','Nb','%','{"%":1}','numeric_or_text',NULL,4,1,720),
('EL_SN','Tin (Sn)','Sn','%','{"%":1}','numeric_or_text',NULL,4,1,730),
('EL_V','Vanadium (V)','V','%','{"%":1}','numeric_or_text',NULL,4,1,740),
('EL_W','Tungsten (W)','W','%','{"%":1}','numeric_or_text',NULL,4,1,750),
('EL_CO','Cobalt (Co)','Co','%','{"%":1}','numeric_or_text',NULL,4,1,760),
-- cement / other
('NORMAL_CONSISTENCY','Normal Consistency','-','%','{"%":1}','numeric',NULL,2,0,800),
('FLEXURAL_STRENGTH','Flexural Strength / Modulus of Rupture','R','N/mm2','{"N/mm2":1,"N/mm²":1,"MPa":1,"kgf/cm2":0.0980665}','numeric',NULL,2,0,810),
('COMPRESSIVE_STRENGTH','Compressive Strength','-','N/mm2','{"N/mm2":1,"N/mm²":1,"MPa":1,"psi":0.00689476}','numeric',NULL,2,0,820),
('BREAKING_STRENGTH','Breaking Strength','B','N','{"N":1,"kN":1000,"kgf":9.80665}','numeric',NULL,2,0,830),
('DENSITY_VALUE','Density','ρ','g/cm3','{"g/cm3":1,"g/cm³":1,"kg/m3":0.001}','numeric',NULL,4,0,840),
('BEND_RESULT','Bend Result','-','-','{}','text',
   '{"Pass":"pass","Fail":"fail","OK":"pass","Cracked":"fail"}',0,0,850),
('VISUAL_OBSERVATION','Visual Observation','-','-','{}','text',NULL,0,0,860),
('GRAIN_SIZE_UM','Average Grain Size','-','µm','{"µm":1,"um":1,"mm":1000}','numeric',NULL,1,0,870),
('GRAIN_SIZE_ASTM','ASTM Grain Size Number','G','-','{"-":1}','numeric',NULL,1,0,880);

-- ─────────────────────────────────────────────────────────────────────────────
--  4. WHICH MEASUREMENTS EACH TEST PRODUCES, AND THE PLAUSIBILITY GUARD RAILS
--
--     normal_min / normal_max = "is this physically possible for steel/plastic"
--       These are deliberately WIDE. They exist to catch typos and unit mix-ups
--       (a 7x-too-large area, a yield above UTS), not to judge quality.
--     spec_min / spec_max = acceptance criteria, filled where a grade is known.
-- ─────────────────────────────────────────────────────────────────────────────

-- helper: insert via SELECT so we reference ids by code
INSERT IGNORE INTO c_test_measurements
 (test_type_id, measurement_id, column_label, is_required, is_computed, formula, normal_min, normal_max, warn_message, sort_order)
SELECT tt.id, m.id, v.label, v.req, v.comp, v.formula, v.nmin, v.nmax, v.warn, v.so
FROM (
  -- TENSILE
  SELECT 'TENSILE' tt, 'DIM_DIAMETER' m, 'Diameter (mm)' label, 0 req, 0 comp, NULL formula, 0.1 nmin, 500 nmax, 'Diameter looks implausible. Check the unit (mm expected).' warn, 10 so UNION ALL
  SELECT 'TENSILE','DIM_WIDTH','Width (mm)',0,0,NULL,0.1,500,'Width looks implausible. Check the unit (mm expected).',20 UNION ALL
  SELECT 'TENSILE','DIM_THICKNESS','Thickness (mm)',0,0,NULL,0.1,500,'Thickness looks implausible. Check the unit (mm expected).',30 UNION ALL
  SELECT 'TENSILE','CROSS_SECTION_AREA','Cross-Section Area (mm2)',0,1,'width * thickness',0.01,100000,'Cross-section area does not match the stated dimensions. Verify or enter the measured area.',40 UNION ALL
  SELECT 'TENSILE','GAUGE_LENGTH','Gauge Length (mm)',0,0,NULL,1,2000,'Gauge length looks implausible.',50 UNION ALL
  SELECT 'TENSILE','SPECIMEN_MASS','Weight (Kg)',0,0,NULL,0.0001,5000,'Specimen mass looks implausible.',60 UNION ALL
  SELECT 'TENSILE','YIELD_LOAD','Yield/Proof Load (kN)',0,0,NULL,0.001,5000,'Yield load looks implausible for a laboratory machine.',70 UNION ALL
  SELECT 'TENSILE','MAX_LOAD','Max Load (kN)',0,0,NULL,0.001,5000,'Maximum load looks implausible for a laboratory machine.',80 UNION ALL
  SELECT 'TENSILE','YS','Yield/Proof Strength (MPa)',0,0,NULL,1,3000,'Yield strength is outside the plausible range for a metal. Check load and area. Also confirm the unit (MPa vs N/mm2 are the same).',90 UNION ALL
  SELECT 'TENSILE','UTS','Ultimate Tensile Strength (MPa)',1,0,NULL,1,3000,'Tensile strength is outside the plausible range. Check load, area and unit.',100 UNION ALL
  SELECT 'TENSILE','ELONGATION','Elongation (%)',0,0,NULL,0,100,'Elongation above 100% is impossible. A 950% reading usually means the value was multiplied by 100 twice.',110 UNION ALL
  -- BRINELL
  SELECT 'HARDNESS_BRINELL','HBW','Reading',0,0,NULL,5,750,'Brinell hardness is outside the range of the method (ASTM E10 covers roughly 5-750 HBW).',10 UNION ALL
  SELECT 'HARDNESS_BRINELL','HBW_MEAN','Brinell Hardness (HBW) Mean',0,1,'MEAN(HBW)','5','750','The mean does not follow from the readings. Check for a corrected or omitted reading.',20 UNION ALL
  -- ROCKWELL
  SELECT 'HARDNESS_ROCKWELL','HRC','Reading',0,0,NULL,-5,75,'HRC is normally between -5 and 75. Values above 68 are unusual for steel; check the scale (HRC vs HRB vs HV).',10 UNION ALL
  SELECT 'HARDNESS_ROCKWELL','HRC_MEAN','Rockwell hardness (HRC) Mean',0,1,'MEAN(HRC)','-5','75','The mean does not follow from the readings.',20 UNION ALL
  SELECT 'HARDNESS_ROCKWELL','HRB','Reading',0,0,NULL,20,120,'HRB is normally between 20 and 120. Check the scale (HRB vs HRC).',30 UNION ALL
  SELECT 'HARDNESS_ROCKWELL','HRB_MEAN','Rockwell Hardness (HRB) Mean',0,1,'MEAN(HRB)',20,120,'The mean does not follow from the readings.',40 UNION ALL
  -- VICKERS / CASE DEPTH
  SELECT 'HARDNESS_VICKERS','HV','HV (Load HV 1Kgf)',0,0,NULL,5,3000,'Vickers hardness is outside the normal range. Check the load and the indentation measurement.',10 UNION ALL
  SELECT 'CASE_DEPTH','CASE_DEPTH_DISTANCE','Case to Core (Distance, mm)',0,0,NULL,0,200,'Distance from the surface looks implausible.',10 UNION ALL
  SELECT 'CASE_DEPTH','HV','HV (Load HV 1Kgf)',0,0,NULL,5,3000,'Vickers hardness is outside the normal range.',20 UNION ALL
  SELECT 'CASE_DEPTH','HRC','HRC',0,0,NULL,-5,75,'HRC out of range for this scale.',30 UNION ALL
  SELECT 'CASE_DEPTH','CASE_DEPTH','Total Case Depth (mm)',0,0,NULL,0.001,50,'Case depth looks implausible.',40 UNION ALL
  -- IMPACT
  SELECT 'IMPACT','IMPACT_ENERGY','Absorbed Energy (J)',1,0,NULL,0,1000,'Absorbed energy is outside the range of a pendulum machine (typically up to 300-450 J).',10 UNION ALL
  SELECT 'IMPACT','SPECIMEN_SIZE','Dimension of Specimen',0,0,NULL,NULL,NULL,NULL,20 UNION ALL
  SELECT 'IMPACT','IMPACT_TEMPERATURE','Temperature',0,0,NULL,-196,1200,'Test temperature is outside a plausible laboratory range.',30 UNION ALL
  -- CORROSION
  SELECT 'CORROSION','CORROSION_RATE','Corrosion Rate (mm/year)',1,0,NULL,0,100,'Corrosion rate looks implausible. Check mass loss, area and exposure time.',10 UNION ALL
  SELECT 'CORROSION','SALT_SPRAY_HOURS','Exposure (hours)',0,0,NULL,0,20000,'Exposure duration looks implausible.',20 UNION ALL
  -- CHEMISTRY
  SELECT 'CHEM_OES','EL_FE','Fe',0,0,NULL,50,100,'Iron content outside 50-100% is implausible for a steel. A value near 0 usually means Balance was entered as a number.',10 UNION ALL
  SELECT 'CHEM_OES','EL_C','C',0,0,NULL,0,5,'Carbon outside 0-5% is implausible for steel.',20 UNION ALL
  SELECT 'CHEM_OES','EL_SI','Si',0,0,NULL,0,20,'Silicon outside 0-20% is implausible for steel.',30 UNION ALL
  SELECT 'CHEM_OES','EL_MN','Mn',0,0,NULL,0,25,'Manganese outside 0-25% is implausible for steel.',40 UNION ALL
  SELECT 'CHEM_OES','EL_P','P',0,0,NULL,0,1,'Phosphorus outside 0-1% is implausible for steel.',50 UNION ALL
  SELECT 'CHEM_OES','EL_S','S',0,0,NULL,0,1,'Sulfur outside 0-1% is implausible for steel.',60 UNION ALL
  SELECT 'CHEM_OES','EL_CR','Cr',0,0,NULL,0,40,'Chromium outside 0-40% is implausible. Above 10.5% suggests stainless steel.',70 UNION ALL
  SELECT 'CHEM_OES','EL_NI','Ni',0,0,NULL,0,40,'Nickel outside 0-40% is implausible.',80 UNION ALL
  SELECT 'CHEM_OES','EL_MO','Mo',0,0,NULL,0,10,'Molybdenum outside 0-10% is implausible.',90 UNION ALL
  SELECT 'CHEM_OES','EL_CU','Cu',0,0,NULL,0,10,'Copper outside 0-10% is implausible.',100 UNION ALL
  SELECT 'CHEM_XRF','EL_FE','Fe',0,0,NULL,50,100,'Iron content outside 50-100% is implausible for a steel.',10 UNION ALL
  SELECT 'CHEM_XRF','EL_C','C',0,0,NULL,0,5,'Carbon outside 0-5% is implausible for steel.',20 UNION ALL
  SELECT 'CHEM_XRF','EL_SI','Si',0,0,NULL,0,20,'Silicon outside 0-20% is implausible.',30 UNION ALL
  SELECT 'CHEM_XRF','EL_MN','Mn',0,0,NULL,0,25,'Manganese outside 0-25% is implausible.',40 UNION ALL
  SELECT 'CHEM_XRF','EL_P','P',0,0,NULL,0,1,'Phosphorus outside 0-1% is implausible.',50 UNION ALL
  SELECT 'CHEM_XRF','EL_S','S',0,0,NULL,0,1,'Sulfur outside 0-1% is implausible.',60 UNION ALL
  SELECT 'CHEM_XRF','EL_CR','Cr',0,0,NULL,0,40,'Chromium outside 0-40% is implausible.',70 UNION ALL
  SELECT 'CHEM_XRF','EL_NI','Ni',0,0,NULL,0,40,'Nickel outside 0-40% is implausible.',80 UNION ALL
  SELECT 'CHEM_XRF','EL_MO','Mo',0,0,NULL,0,10,'Molybdenum outside 0-10% is implausible.',90 UNION ALL
  SELECT 'CHEM_XRF','EL_CU','Cu',0,0,NULL,0,10,'Copper outside 0-10% is implausible.',100 UNION ALL
  -- CEMENT / PLASTIC / OTHER
  SELECT 'CEMENT_CONSISTENCY','NORMAL_CONSISTENCY','Normal Consistency (%)',1,0,NULL,5,60,'Normal consistency outside 5-60% is unusual for cement paste.',10 UNION ALL
  SELECT 'CEMENT_FLEXURAL','FLEXURAL_STRENGTH','Modulus of Rupture (R) N/mm2',1,0,NULL,0.1,50,'Modulus of rupture outside the normal range for cement mortar.',10 UNION ALL
  SELECT 'CEMENT_FLEXURAL','BREAKING_STRENGTH','Breaking Strength (B) N',0,0,NULL,0,50000,'Breaking load looks implausible.',20 UNION ALL
  SELECT 'PLASTIC_TENSILE','UTS','Tensile Strength (MPa)',1,0,NULL,0.1,500,'Plastic tensile strength is normally well below 500 MPa.',10 UNION ALL
  SELECT 'PLASTIC_TENSILE','ELONGATION','Elongation (%)',0,0,NULL,0,2000,'Elongation of a plastic can be very large; verify the figure.',20 UNION ALL
  SELECT 'BEND','BEND_RESULT','Result',1,0,NULL,NULL,NULL,NULL,10 UNION ALL
  SELECT 'VISUAL','VISUAL_OBSERVATION','Observation',0,0,NULL,NULL,NULL,NULL,10
) AS v
JOIN c_test_types tt ON tt.code = v.tt
JOIN c_measurements m ON m.code = v.m;

-- ─────────────────────────────────────────────────────────────────────────────
--  5. MATERIAL GRADE LIMITS  (drives the PASS / FAIL suggestion)
--     Values from the published standards. Where a standard gives a range, the
--     minimum is the acceptance criterion and the maximum is recorded too.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO c_grade_limits (grade_code, grade_name, material_group, measurement_id, min_value, max_value, requirement_text, standard_id)
SELECT v.gc, v.gn, v.mg, m.id, v.mn, v.mx, v.txt,
       (SELECT s.id FROM c_standards s
          WHERE s.code = CASE
            WHEN v.gc LIKE 'ASTM A615%' THEN 'ASTM A615/A615M'
            WHEN v.gc LIKE 'ASTM A706%' THEN 'ASTM A706/A706M'
            WHEN v.gc LIKE 'ASTM A36%'  THEN 'ASTM A36/A36M'
            WHEN v.gc LIKE 'ASTM A572%' THEN 'ASTM A572/A572M'
            WHEN v.gc LIKE 'SS %'       THEN 'ASTM E1086'
            ELSE NULL END
          LIMIT 1)
FROM (
  -- ASTM A615 rebar grades (metric). Yield minimum is the acceptance value.
  SELECT 'ASTM A615 Gr.40' gc,'ASTM A615 Grade 40 Rebar' gn,'steel' mg,'YS' m,280 mn,420 mx,'Minimum yield strength 280 MPa (40 000 psi)' txt,'ASTM A615/A615M' std UNION ALL
  SELECT 'ASTM A615 Gr.40','ASTM A615 Grade 40 Rebar','steel','UTS',420,620,'Minimum tensile strength 420 MPa','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.40','ASTM A615 Grade 40 Rebar','steel','ELONGATION',11,NULL,'Minimum elongation 11% (bar size dependent)','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.60','ASTM A615 Grade 60 Rebar','steel','YS',420,550,'Minimum yield strength 420 MPa (60 000 psi)','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.60','ASTM A615 Grade 60 Rebar','steel','UTS',620,800,'Minimum tensile strength 620 MPa','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.60','ASTM A615 Grade 60 Rebar','steel','ELONGATION',9,NULL,'Minimum elongation 9% (bar size dependent)','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.75','ASTM A615 Grade 75 Rebar','steel','YS',520,700,'Minimum yield strength 520 MPa','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.75','ASTM A615 Grade 75 Rebar','steel','UTS',690,900,'Minimum tensile strength 690 MPa','ASTM A615/A615M' UNION ALL
  SELECT 'ASTM A615 Gr.75','ASTM A615 Grade 75 Rebar','steel','ELONGATION',7,NULL,'Minimum elongation 7%','ASTM A615/A615M' UNION ALL
  -- ASTM A706 low-alloy rebar
  SELECT 'ASTM A706 Gr.60','ASTM A706 Grade 60 Low-Alloy Rebar','steel','YS',420,540,'Yield 420-540 MPa (a maximum applies, unlike A615)','ASTM A706/A706M' UNION ALL
  SELECT 'ASTM A706 Gr.60','ASTM A706 Grade 60 Low-Alloy Rebar','steel','UTS',550,720,'Minimum tensile strength 550 MPa','ASTM A706/A706M' UNION ALL
  SELECT 'ASTM A706 Gr.60','ASTM A706 Grade 60 Low-Alloy Rebar','steel','ELONGATION',14,NULL,'Minimum elongation 14%','ASTM A706/A706M' UNION ALL
  -- ASTM A36 structural steel
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','YS',250,400,'Minimum yield strength 250 MPa for thickness up to 20 mm','ASTM A36/A36M' UNION ALL
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','UTS',400,550,'Tensile strength 400-550 MPa','ASTM A36/A36M' UNION ALL
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','ELONGATION',20,NULL,'Minimum elongation 20% (200 mm gauge, thickness dependent)','ASTM A36/A36M' UNION ALL
  -- ASTM A572 Gr.50 (appears in the Sukkur barrage report as a reference row)
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','YS',345,NULL,'Minimum yield strength 345 MPa','ASTM A572/A572M' UNION ALL
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','UTS',450,NULL,'Minimum tensile strength 450 MPa','ASTM A572/A572M' UNION ALL
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','ELONGATION',18,21,'Elongation 18-21% depending on thickness','ASTM A572/A572M' UNION ALL
  -- Chemistry limits commonly quoted for these grades
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','EL_C',NULL,0.25,'Maximum carbon 0.25% (plate up to 20 mm)','ASTM A36/A36M' UNION ALL
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','EL_MN',NULL,1.20,'Manganese 0.60-1.20% depending on product','ASTM A36/A36M' UNION ALL
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','EL_P',NULL,0.04,'Maximum phosphorus 0.04%','ASTM A36/A36M' UNION ALL
  SELECT 'ASTM A36','ASTM A36 Carbon Structural Steel','steel','EL_S',NULL,0.05,'Maximum sulfur 0.05%','ASTM A36/A36M' UNION ALL
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','EL_C',NULL,0.23,'Maximum carbon 0.23%','ASTM A572/A572M' UNION ALL
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','EL_MN',NULL,1.35,'Maximum manganese 1.35%','ASTM A572/A572M' UNION ALL
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','EL_P',NULL,0.04,'Maximum phosphorus 0.04%','ASTM A572/A572M' UNION ALL
  SELECT 'ASTM A572 Gr.50','ASTM A572 Grade 50 HSLA Steel','steel','EL_S',NULL,0.05,'Maximum sulfur 0.05%','ASTM A572/A572M' UNION ALL
  -- Stainless (grade identified by chromium and nickel, no strength limit)
  SELECT 'SS 304','Stainless Steel 304','stainless','EL_CR',18,20,'Chromium 18-20%','ASTM E1086' UNION ALL
  SELECT 'SS 304','Stainless Steel 304','stainless','EL_NI',8,10.5,'Nickel 8-10.5%','ASTM E1086' UNION ALL
  SELECT 'SS 304','Stainless Steel 304','stainless','EL_C',NULL,0.08,'Maximum carbon 0.08%','ASTM E1086' UNION ALL
  SELECT 'SS 316','Stainless Steel 316','stainless','EL_CR',16,18,'Chromium 16-18%','ASTM E1086' UNION ALL
  SELECT 'SS 316','Stainless Steel 316','stainless','EL_NI',10,14,'Nickel 10-14%','ASTM E1086' UNION ALL
  SELECT 'SS 316','Stainless Steel 316','stainless','EL_MO',2,3,'Molybdenum 2-3% (the defining addition over 304)','ASTM E1086' UNION ALL
  SELECT 'SS 316','Stainless Steel 316','stainless','EL_C',NULL,0.08,'Maximum carbon 0.08%','ASTM E1086'
) AS v
JOIN c_measurements m ON m.code = v.m;

-- ─────────────────────────────────────────────────────────────────────────────
--  6. TEST SUGGESTIONS
--     "When a sample looks like this, these tests are usually ordered."
--     Seeded from the 23 real consultancy reports analysed, then improved by use.
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO c_test_suggestions (keyword, material_group, test_type_id, confidence, times_used, source)
SELECT v.kw, v.mg, tt.id, v.conf, v.used, 'seeded'
FROM (
  -- rebar / steel bar: tensile + chemistry are near-universal; hardness common
  SELECT 'rebar' kw,'steel' mg,'TENSILE' tt,0.9500 conf,1 used UNION ALL
  SELECT 'rebar','steel','CHEM_OES',0.9000,1 UNION ALL
  SELECT 'rebar','steel','HARDNESS_ROCKWELL',0.6500,1 UNION ALL
  SELECT 'rebar','steel','BEND',0.4000,1 UNION ALL
  SELECT 'steel bar','steel','TENSILE',0.9500,1 UNION ALL
  SELECT 'steel bar','steel','CHEM_OES',0.9000,1 UNION ALL
  SELECT 'steel bar','steel','HARDNESS_BRINELL',0.7000,1 UNION ALL
  SELECT 'steel bar','steel','HARDNESS_ROCKWELL',0.6000,1 UNION ALL
  SELECT 'steel bar','steel','MICROSTRUCTURE',0.6500,1 UNION ALL
  -- structural steel / plate
  SELECT 'steel plate','steel','TENSILE',0.9000,1 UNION ALL
  SELECT 'steel plate','steel','CHEM_OES',0.8500,1 UNION ALL
  SELECT 'steel plate','steel','IMPACT',0.7000,1 UNION ALL
  SELECT 'steel plate','steel','MICROSTRUCTURE',0.6000,1 UNION ALL
  SELECT 'mild steel','steel','TENSILE',0.9000,1 UNION ALL
  SELECT 'mild steel','steel','HARDNESS_ROCKWELL',0.7500,1 UNION ALL
  SELECT 'mild steel','steel','CHEM_OES',0.7000,1 UNION ALL
  -- case-hardened / tool steel: depth profile is the point of the test
  SELECT 'case depth','steel','CASE_DEPTH',0.9800,1 UNION ALL
  SELECT 'case depth','steel','HARDNESS_ROCKWELL',0.7000,1 UNION ALL
  SELECT 'case depth','steel','MICROSTRUCTURE',0.6500,1 UNION ALL
  SELECT 'hardened','steel','CASE_DEPTH',0.8500,1 UNION ALL
  SELECT 'hardened','steel','HARDNESS_ROCKWELL',0.9000,1 UNION ALL
  SELECT 'hardened','steel','MICROSTRUCTURE',0.7000,1 UNION ALL
  -- bolt / fastener
  SELECT 'bolt','steel','CHEM_XRF',0.9000,1 UNION ALL
  SELECT 'bolt','steel','TENSILE',0.8000,1 UNION ALL
  SELECT 'bolt','steel','HARDNESS_ROCKWELL',0.7000,1 UNION ALL
  SELECT 'fastener','steel','CHEM_XRF',0.8500,1 UNION ALL
  SELECT 'fastener','steel','TENSILE',0.7500,1 UNION ALL
  -- stainless
  SELECT 'stainless','stainless','CHEM_OES',0.9500,1 UNION ALL
  SELECT 'stainless','stainless','CHEM_XRF',0.8000,1 UNION ALL
  SELECT 'stainless','stainless','TENSILE',0.7000,1 UNION ALL
  SELECT 'ss 304','stainless','CHEM_OES',0.9500,1 UNION ALL
  SELECT 'ss 316','stainless','CHEM_OES',0.9500,1 UNION ALL
  -- wire / netting
  SELECT 'wire','steel','TENSILE',0.9000,1 UNION ALL
  SELECT 'wire','steel','CHEM_OES',0.7000,1 UNION ALL
  SELECT 'wire netting','steel','TENSILE',0.8500,1 UNION ALL
  SELECT 'netting','steel','TENSILE',0.8000,1 UNION ALL
  SELECT 'galvanized','steel','CHEM_XRF',0.8500,1 UNION ALL
  SELECT 'galvanized','steel','CORROSION',0.7000,1 UNION ALL
  -- corrosion work
  SELECT 'corrosion','steel','CORROSION',0.9500,1 UNION ALL
  SELECT 'salt spray','steel','CORROSION',0.9800,1 UNION ALL
  SELECT 'coating','steel','CORROSION',0.8000,1 UNION ALL
  -- cement / construction
  SELECT 'cement','cement','CEMENT_CONSISTENCY',0.9500,1 UNION ALL
  SELECT 'cement','cement','CEMENT_FLEXURAL',0.8500,1 UNION ALL
  SELECT 'masonry cement','cement','CEMENT_CONSISTENCY',0.9500,1 UNION ALL
  SELECT 'masonry cement','cement','CEMENT_FLEXURAL',0.9000,1 UNION ALL
  SELECT 'marble','stone','FLEXURAL_STRENGTH',0.8000,1 UNION ALL
  SELECT 'marble','stone','COMPRESSIVE_STRENGTH',0.8500,1 UNION ALL
  -- plastics / polymers
  SELECT 'polystyrene','polymer','PLASTIC_TENSILE',0.9000,1 UNION ALL
  SELECT 'polystyrene','polymer','PLASTIC_FLEXURAL',0.8000,1 UNION ALL
  SELECT 'polystyrene','polymer','DENSITY',0.7500,1 UNION ALL
  SELECT 'plastic','polymer','PLASTIC_TENSILE',0.8500,1 UNION ALL
  SELECT 'plastic','polymer','DENSITY',0.7000,1 UNION ALL
  -- electronics (Example Client appliances)
  SELECT 'handfree','electronics','VISUAL',0.9000,1 UNION ALL
  SELECT 'handsfree','electronics','VISUAL',0.9000,1 UNION ALL
  SELECT 'gas stove','electronics','VISUAL',0.9000,1 UNION ALL
  SELECT 'electric iron','electronics','VISUAL',0.9000,1 UNION ALL
  SELECT 'fan','electronics','VISUAL',0.8500,1 UNION ALL
  SELECT 'kettle','electronics','VISUAL',0.8500,1 UNION ALL
  SELECT 'dental','other','HARDNESS_VICKERS',0.9000,1 UNION ALL
  SELECT 'dental','other','MICROSTRUCTURE',0.7000,1
) AS v
JOIN c_test_types tt ON tt.code = v.tt;

-- ─────────────────────────────────────────────────────────────────────────────
--  7. COUNTERS AND SETTINGS
-- ─────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO c_counters (counter_key, next_number, prefix) VALUES
('invoice', 1, 'INV'),
('sample', 1, 'SMP'),
('report', 1, 'RPT');

INSERT IGNORE INTO c_settings (setting_key, setting_value, description) VALUES
('lab_name','Department of Materials Engineering','Printed in the report masthead'),
('lab_full_name','Example Technical University','Printed in the report masthead'),
('lab_unit_name','MATERIAL TESTING LABORATORIES','Printed in the report masthead'),
('lab_phone','99261261-68, Ext.: 2516','Printed in the report masthead'),
('lab_fax','99261255','Printed in the report masthead'),
('lab_email','alidad@Example University.edu.pk','Printed in the report masthead'),
('default_tax_rate','13','Default service tax percentage on invoices'),
('currency','Rs.','Currency symbol printed on invoices'),
('validation_mode','warn','warn = alert but never block. block = refuse impossible values. Do not change without laboratory approval.'),
('require_override_reason','1','Ask for a reason when a technician proceeds past a warning'),
('report_default_remarks','Results pertain to the sample received and sampling was done at customer''s end.\nNo counter portion/un-expended part of the sample has been retained.\nTest report cannot be made a part of the litigation / court proceeding or other commercial purposes etc.','Standard closing remarks block'),
('report_company_prefix','Example University-MYD','Prefix for generated report numbers'),
('verification_base_url','','Public base URL used in the report QR code');

-- ============================================================================
--  SEED COMPLETE
-- ============================================================================
