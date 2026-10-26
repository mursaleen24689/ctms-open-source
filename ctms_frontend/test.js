import fs from 'fs';

const BASE = 'http://localhost/ctms_server/api';
let token = '';

async function request(endpoint, options = {}) {
  const url = `${BASE}${endpoint}`;
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const response = await fetch(url, { ...options, headers: { ...headers, ...options.headers } });
  const text = await response.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    throw new Error(`Invalid JSON from ${endpoint}: ${text}`);
  }
}

async function runTest() {
  try {
    console.log("=== STARTING LIVE TEST PROTOCOL ===");

    // 1. Login
    console.log("Logging in as EMP-001...");
    const authRes = await request('/auth.php', {
      method: 'POST',
      body: JSON.stringify({ employee_id: 'EMP-001', password: 'admin123' })
    });
    if (!authRes.token) throw new Error("Login failed: " + JSON.stringify(authRes));
    token = authRes.token;
    console.log("-> Success");

    // 2. Phase 1: Create Lot
    console.log("\nPhase 1: Creating Lot...");
    const lotRes = await request('/lots.php', {
      method: 'POST',
      body: JSON.stringify({ generate_number_only: true, lot_type: 'CHEQUE' })
    });
    const lotNum = lotRes.lot_number;
    const createLotRes = await request('/lots.php', {
      method: 'POST',
      body: JSON.stringify({ lot_number: lotNum, lot_type: 'CHEQUE', agency_type: 'CHEQUE' })
    });
    const lotId = createLotRes.id;
    console.log(`-> Lot ${lotNum} created with ID ${lotId}`);

    // Create Samples for Lot
    console.log("Phase 1: Adding samples...");
    const sampleData = {
      lot_id: lotId,
      lot_type: 'CHEQUE',
      samples: [
        { sample_category: 'STEEL', client_name: 'Test Client', request_number: 'REQ-01', sd_number: 'SD-' + Date.now(), extra_data: {} },
        { sample_category: 'STEEL', client_name: 'Test Client 2', request_number: 'REQ-02', sd_number: 'SD-' + (Date.now()+1), extra_data: {} }
      ]
    };
    const sampleRes = await request('/samples.php', {
      method: 'POST',
      body: JSON.stringify(sampleData)
    });
    if (!sampleRes.success) throw new Error("Failed to create samples: " + JSON.stringify(sampleRes));
    console.log("-> Samples added");

    // Get the sample IDs
    const getSamplesRes = await request(`/samples.php?lot_id=${lotId}`);
    const sampleIds = getSamplesRes.map(s => s.id);

    // 3. Phase 2: Route Samples to Tensile Lab
    console.log("\nPhase 2: Routing samples to Tensile Lab...");
    const routeRes = await request('/samples.php', {
      method: 'PUT',
      body: JSON.stringify({ action: 'route', sample_ids: sampleIds, status: 'in_testing_tensile', lab_stream: 'Tensile Lab' })
    });
    console.log("-> Samples routed:", routeRes);

    // 4. Phase 3: Enter Test Results
    console.log("\nPhase 3: Entering results for Tensile Lab...");
    const resultsPayload = {
      lot_id: lotId,
      lab_stream: 'Tensile',
      results: sampleIds.map(sid => ({
        sample_id: sid,
        values: {
          'Dia (mm)': '12',
          'Yield Strength (MPa)': '450',
          'Ultimate Tensile Strength (MPa)': '550',
          'Elongation %': '22'
        }
      }))
    };
    const resultRes = await request('/results.php', {
      method: 'POST',
      body: JSON.stringify(resultsPayload)
    });
    console.log("-> Results submitted:", resultRes);

    // 5. Phase 5: Check Analytics Dashboard
    console.log("\nPhase 5: Fetching Dashboard Analytics...");
    const analytics = await request('/analytics.php');
    console.log("-> Dashboard Data Sample:", JSON.stringify(analytics).slice(0, 150) + "...");

    console.log("\n=== ALL TESTS PASSED SUCCESSFULLY ===");

  } catch (err) {
    console.error("\nTEST FAILED:", err.message);
    process.exit(1);
  }
}

runTest();
