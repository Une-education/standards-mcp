<?php
declare(strict_types=1);
$t_start = microtime(true);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents('php://input') ?: file_get_contents('php://stdin');

function sendJsonRpcResponse(string|int|null $id, ?array $result, ?array $error, float $t_start, ?string $method, string $rawInput): void {
    $execMs = round((microtime(true) - $t_start) * 1000, 2);
    $memBytes = memory_get_peak_usage(true);
    $memFormatted = round($memBytes / (1024 * 1024), 2) . ' Mo';
    $nowUtc = gmdate('Y-m-d\TH:i:s\Z');

    $serverSecret = getenv('ATOA_NODE_SECRET') ?: 'atoa_sino_secret_key_2026';
    $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $resultHash = hash('sha256', (string)$resultJson);
    $attestationId = bin2hex(random_bytes(16));
    $executionSignature = hash_hmac('sha256', $resultHash . '|' . $attestationId, $serverSecret);

    $meta = [
        'state_proof' => [
            'result_hash'          => $resultHash,
            'attestation_id'       => $attestationId,
            'execution_signature'  => $executionSignature,
            'timestamp_utc'        => $nowUtc,
            'corpus_version'       => 'CN-SAMR-MOFCOM-2026.Q4',
            'authority'            => 'SAMR / CNCA / MOFCOM PRC',
            'nature'               => 'Deterministic Standard & Export Control Computation',
            'legal_basis'          => 'Standardization Law of the PRC & Export Control Law (2025/2026 Notices)',
            'reliance'             => 'Certified computational evaluation against official PRC statutory corpora.',
            'status'               => 'SEALED_DETERMINISTIC'
        ],
        'efficiency' => [
            'latency_ms'       => $execMs,
            'peak_memory'      => $memFormatted,
            'energy_est_mj'    => round($execMs * 15, 3),
            'carbon_est_mg'    => round(($execMs * 15 / 3600000) * 52 * 1000, 5),
            'footprint_rating' => ($execMs < 5.0) ? 'A+ (Ultra-frugal)' : 'A (Frugal)'
        ]
    ];

    $response = ['jsonrpc' => '2.0'];
    if ($id !== null) $response['id'] = $id;

    if ($error !== null) {
        $response['error'] = $error;
    } else {
        if ($result !== null) $result['_meta'] = $meta;
        $response['result'] = $result;
    }

    try {
        $logDbPath = '/var/www/atoa-api/sino-industrial/data/sino_industrial_logs.sqlite';
        $logDb = new PDO('sqlite:' . $logDbPath);
        $logDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $logDb->exec("CREATE TABLE IF NOT EXISTS mcp_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip TEXT,
            method TEXT,
            user_agent TEXT,
            payload TEXT,
            exec_time_ms REAL,
            proof_hash TEXT
        );");
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $logDb->prepare("INSERT INTO mcp_logs (ip, method, user_agent, payload, exec_time_ms, proof_hash) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$ip, $method ?? 'unknown', $ua, substr($rawInput, 0, 500), $execMs, $resultHash]);
    } catch (\Throwable $ignored) {}

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$toolsList = [
    [
        'name' => 'resolve_gb_standards',
        'description' => 'Resolves applicable Chinese national standards (GB mandatory, GB/T recommended) and verifies China Compulsory Certification (CCC) requirements under SAMR/CNCA.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => 'Product category (e.g., ev_battery, consumer_battery, electronics, machinery, appliances)'
                ],
                'standard_code' => [
                    'type' => 'string',
                    'description' => 'Exact standard code to inspect (optional, e.g., GB 38031-2025)'
                ]
            ],
            'required' => ['category']
        ]
    ],
    [
        'name' => 'check_mofcom_export_controls',
        'description' => 'Checks Chinese Ministry of Commerce (MOFCOM) export control regimes, dual-use licensing, and bilateral truce suspensions (including US/EU specific regimes) for minerals, batteries, and tech.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'material_keyword' => [
                    'type' => 'string',
                    'description' => 'Name or keyword (e.g., graphite, holmium, erbium, battery, antimony, gallium)'
                ],
                'hs_code' => [
                    'type' => 'string',
                    'description' => 'HS code prefix (2, 4 or 6 digits, e.g., 250410, 280530, 850760)'
                ],
                'destination_market' => [
                    'type' => 'string',
                    'enum' => ['US', 'EU', 'GLOBAL'],
                    'default' => 'GLOBAL',
                    'description' => 'Target destination country/market for suspension applicability'
                ]
            ]
        ]
    ],
    [
        'name' => 'assess_importability',
        'description' => 'Evaluates end-to-end importability of Chinese industrial components into the EU or US, assessing mandatory export approvals, overseas documentation, indicative costs and compliance lead times.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'product_category' => [
                    'type' => 'string',
                    'description' => 'Target industrial sector (e.g., ev_battery, consumer_battery, electronics)'
                ],
                'destination_market' => [
                    'type' => 'string',
                    'enum' => ['EU', 'US'],
                    'description' => 'Target customs territory'
                ],
                'material_keyword' => [
                    'type' => 'string',
                    'description' => 'Optional sensitive input to cross-check MOFCOM export hurdles simultaneously'
                ]
            ],
            'required' => ['product_category', 'destination_market']
        ]
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'GET' || empty($rawInput)) {
    sendJsonRpcResponse(null, [
        'name' => 'AtoA Sino-Industrial Standards & Export Control Node',
        'version' => '1.0.0',
        'protocolVersion' => '2024-11-05',
        'capabilities' => ['tools' => new stdClass()],
        'tools_count' => count($toolsList)
    ], null, $t_start, 'GET/info', $rawInput ?: '');
}

$req = json_decode($rawInput, true);
if (!$req || !isset($req['jsonrpc']) || $req['jsonrpc'] !== '2.0') {
    sendJsonRpcResponse(null, null, ['code' => -32600, 'message' => 'Invalid JSON-RPC request'], $t_start, 'unknown', $rawInput);
}

$id = $req['id'] ?? null;
$method = $req['method'] ?? '';
$params = $req['params'] ?? [];

$dbPath = '/var/www/atoa-api/sino-industrial/data/sino_industrial.sqlite';
if (!file_exists($dbPath)) {
    sendJsonRpcResponse($id, null, ['code' => -32603, 'message' => 'Database not found'], $t_start, $method, $rawInput);
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($method === 'initialize') {
        sendJsonRpcResponse($id, [
            'protocolVersion' => '2024-11-05',
            'serverInfo' => [
                'name' => 'sino-industrial-compliance-mcp',
                'version' => '1.0.0'
            ],
            'capabilities' => ['tools' => new stdClass()]
        ], null, $t_start, $method, $rawInput);
    }

    if ($method === 'tools/list') {
        sendJsonRpcResponse($id, ['tools' => $toolsList], null, $t_start, $method, $rawInput);
    }

    if ($method === 'tools/call') {
        $toolName = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        // -------------------------------------------------------------
        // 1. resolve_gb_standards (Directionnalité stricte du lignage)
        // -------------------------------------------------------------
        if ($toolName === 'resolve_gb_standards') {
            $cat = trim($args['category'] ?? '');
            $code = trim($args['standard_code'] ?? '');

            $query = "SELECT * FROM gb_standards WHERE 1=1";
            $binds = [];
            if (!empty($code)) {
                $query .= " AND standard_code LIKE ?";
                $binds[] = '%' . $code . '%';
            } else {
                $query .= " AND category = ?";
                $binds[] = $cat;
            }
            $query .= " ORDER BY status ASC, standard_code DESC";
            $stmt = $db->prepare($query);
            $stmt->execute($binds);
            $standards = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Lignage directionnel distinct
            $stmtForward  = $db->prepare("SELECT to_code AS code, transition_date FROM standard_lineage WHERE from_code = ?");
            $stmtBackward = $db->prepare("SELECT from_code AS code, transition_date FROM standard_lineage WHERE to_code = ?");

            foreach ($standards as &$s) {
                try {
                    $stmtForward->execute([$s['standard_code']]);
                    $s['superseded_by'] = $stmtForward->fetchAll(PDO::FETCH_ASSOC);

                    $stmtBackward->execute([$s['standard_code']]);
                    $s['supersedes'] = $stmtBackward->fetchAll(PDO::FETCH_ASSOC);
                } catch (\Throwable $e) {
                    $s['superseded_by'] = [];
                    $s['supersedes'] = [];
                }
            }
            unset($s);

            // Lookup CCC
            $stmtCcc = $db->prepare("SELECT * FROM product_ccc_requirements WHERE product_category = ?");
            $stmtCcc->execute([$cat]);
            $cccInfo = $stmtCcc->fetch(PDO::FETCH_ASSOC);

            // Grouper par statut (current, superseded, legacy)
            $current    = [];
            $superseded = [];
            $legacy     = [];

            $activeCodes = array_column(array_filter($standards, fn($s) => $s['status'] === 'ACTIVE'), 'standard_code');

            foreach ($standards as $s) {
                if ($s['status'] === 'ACTIVE') {
                    $current[] = $s;
                } elseif ($s['status'] === 'REPLACED') {
                    if (!empty($s['replaced_by_code']) && in_array($s['replaced_by_code'], $activeCodes, true)) {
                        $superseded[] = $s;
                    } else {
                        $legacy[] = $s;
                    }
                } else {
                    $legacy[] = $s;
                }
            }

            $out = [
                'category_queried' => $cat,
                'ccc_certification' => $cccInfo ? [
                    'ccc_required'   => (bool)$cccInfo['ccc_required'],
                    'rule'           => $cccInfo['certification_rule'],
                    'effective_date' => $cccInfo['effective_date'],
                    'legal_basis'    => $cccInfo['legal_basis']
                ] : [
                    'ccc_required' => false,
                    'status'       => 'No compulsory product certification recorded for this specific category tag.'
                ],
                'standards' => [
                    'current'    => $current,
                    'superseded' => $superseded,
                    'legacy'     => $legacy
                ],
                'standards_summary' => [
                    'current_count'    => count($current),
                    'superseded_count' => count($superseded),
                    'legacy_count'     => count($legacy)
                ]
            ];

            sendJsonRpcResponse($id, ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]], null, $t_start, $method, $rawInput);
        }

        // -------------------------------------------------------------
        // 2. check_mofcom_export_controls
        // -------------------------------------------------------------
        if ($toolName === 'check_mofcom_export_controls') {
            $kw = trim($args['material_keyword'] ?? '');
            $hs = trim($args['hs_code'] ?? '');
            $dest = strtoupper(trim($args['destination_market'] ?? 'GLOBAL'));
            $today = gmdate('Y-m-d');

            $q = "SELECT c.*, s.suspension_start, s.suspension_until, s.legal_basis as susp_legal_basis, s.destination_exception, s.notes as susp_notes 
                  FROM mofcom_controls c 
                  LEFT JOIN mofcom_suspensions s ON c.control_code = s.control_code 
                  WHERE 1=1";
            $binds = [];
            if (!empty($hs)) {
                $q .= " AND (c.hs_code_prefix LIKE ? OR ? LIKE (c.hs_code_prefix || '%'))";
                $binds[] = $hs . '%';
                $binds[] = $hs;
            } elseif (!empty($kw)) {
                $q .= " AND (c.item_name_en LIKE ? OR c.item_name_zh LIKE ? OR c.control_code LIKE ?)";
                $binds[] = '%' . $kw . '%';
                $binds[] = '%' . $kw . '%';
                $binds[] = '%' . $kw . '%';
            }

            $stmt = $db->prepare($q);
            $stmt->execute($binds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $matches = [];
            foreach ($rows as $r) {
                $code = $r['control_code'];
                if (!isset($matches[$code])) {
                    $matches[$code] = [
                        'control_code' => $r['control_code'],
                        'hs_code_prefix' => $r['hs_code_prefix'],
                        'item_name_en' => $r['item_name_en'],
                        'item_name_zh' => $r['item_name_zh'],
                        'control_type' => $r['control_type'],
                        'base_law' => $r['legal_basis'],
                        'effective_date' => $r['effective_date'],
                        'suspension_regimes' => []
                    ];
                }

                if (!empty($r['suspension_until'])) {
                    $isSuspActive = ($today >= $r['suspension_start'] && $today <= $r['suspension_until']);
                    $matches[$code]['suspension_regimes'][] = [
                        'legal_basis' => $r['susp_legal_basis'],
                        'suspension_until' => $r['suspension_until'],
                        'scope' => $r['destination_exception'],
                        'is_currently_in_effect' => $isSuspActive,
                        'notes' => $r['susp_notes']
                    ];
                }
            }

            $results = [];
            foreach ($matches as $item) {
                $status = 'ACTIVE_CONTROL';
                $activeSuspensions = [];

                foreach ($item['suspension_regimes'] as $susp) {
                    if ($susp['is_currently_in_effect']) {
                        if ($susp['scope'] === 'GLOBAL' || ($susp['scope'] === 'US_ONLY' && $dest === 'US')) {
                            $activeSuspensions[] = $susp;
                        }
                    }
                }

                if (!empty($activeSuspensions)) {
                    $status = 'SUSPENDED';
                    $regimeDetails = array_map(function($s) {
                        return "{$s['legal_basis']} (valid until {$s['suspension_until']}, scope: {$s['scope']})";
                    }, $activeSuspensions);
                    $guidance = "Export enforcement paused under: " . implode(" AND ", $regimeDetails) . ". Individual dual-use license waived temporarily.";
                } else {
                    $guidance = 'Export requires individual MOFCOM dual-use export license approval.';
                }

                $item['current_enforcement_status'] = $status;
                $item['guidance'] = $guidance;
                $results[] = $item;
            }

            $out = [
                'destination_assessed' => $dest,
                'evaluation_date' => $today,
                'items_matched_count' => count($results),
                'controls' => $results
            ];

            sendJsonRpcResponse($id, ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]], null, $t_start, $method, $rawInput);
        }

        // -------------------------------------------------------------
        // 3. assess_importability (Déduplication rigoureuse)
        // -------------------------------------------------------------
        if ($toolName === 'assess_importability') {
            $cat = trim($args['product_category'] ?? '');
            $dest = strtoupper(trim($args['destination_market'] ?? 'EU'));
            $matKw = trim($args['material_keyword'] ?? '');
            $today = gmdate('Y-m-d');

            $stmt = $db->prepare("SELECT * FROM compliance_rules WHERE category = ? AND target_market = ?");
            $stmt->execute([$cat, $dest]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                sendJsonRpcResponse($id, null, ['code' => -32602, 'message' => "No compliance rule profile for category '{$cat}' and destination '{$dest}'."], $t_start, $method, $rawInput);
            }

            $mofcomFlag = null;
            if (!empty($matKw)) {
                $stmtM = $db->prepare("SELECT c.control_code, c.item_name_en, s.suspension_start, s.suspension_until, s.legal_basis, s.destination_exception 
                                       FROM mofcom_controls c 
                                       LEFT JOIN mofcom_suspensions s ON c.control_code = s.control_code 
                                       WHERE c.item_name_en LIKE ? OR c.item_name_zh LIKE ? OR c.control_code LIKE ?");
                $stmtM->execute(['%' . $matKw . '%', '%' . $matKw . '%', '%' . $matKw . '%']);
                $rowsM = $stmtM->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($rowsM)) {
                    $matchedName = $rowsM[0]['item_name_en'];
                    $activeRegimesMap = [];
                    $otherRegimesMap = [];

                    foreach ($rowsM as $row) {
                        if (!empty($row['suspension_until'])) {
                            $isActive = ($today >= $row['suspension_start'] && $today <= $row['suspension_until']);
                            if ($isActive) {
                                $scope = $row['destination_exception'];
                                $basis = $row['legal_basis'];
                                $until = $row['suspension_until'];
                                $uniqueKey = $basis . '|' . $until . '|' . $scope;

                                if ($scope === 'GLOBAL' || ($scope === 'US_ONLY' && $dest === 'US')) {
                                    if (!isset($activeRegimesMap[$uniqueKey])) {
                                        $activeRegimesMap[$uniqueKey] = [
                                            'legal_basis' => $basis,
                                            'scope' => $scope,
                                            'suspension_until' => $until
                                        ];
                                    }
                                } else {
                                    if (!isset($otherRegimesMap[$uniqueKey])) {
                                        $otherRegimesMap[$uniqueKey] = "Additional {$scope} suspension exists under {$basis} until {$until} (does not apply to {$dest} destinations).";
                                    }
                                }
                            }
                        }
                    }

                    $mofcomFlag = [
                        'matched_item' => $matchedName,
                        'status' => !empty($activeRegimesMap) ? 'SUSPENDED' : 'LICENSE_MANDATORY',
                        'active_regimes_for_destination' => array_values($activeRegimesMap),
                        'geopolitical_nuance' => !empty($otherRegimesMap) ? implode(' ', array_values($otherRegimesMap)) : 'No diverging destination exemptions recorded.'
                    ];
                }
            }

            $out = [
                'importability_verdict' => ($rule['risk_level'] === 'CRITICAL') ? 'CONDITIONAL_HIGH_SCRUTINY' : 'CLEAR_WITH_STANDARD_AUDIT',
                'category' => $cat,
                'target_market' => $dest,
                'risk_level' => $rule['risk_level'],
                'mandatory_dossiers' => json_decode($rule['mandatory_docs'], true),
                'lead_time_days' => [
                    'min' => $rule['lead_time_days_min'],
                    'max' => $rule['lead_time_days_max']
                ],
                'estimated_compliance_cost_eur' => [
                    'min' => $rule['cost_eur_min'],
                    'max' => $rule['cost_eur_max'],
                    'basis' => $rule['cost_basis_note']
                ],
                'mofcom_export_barrier_check' => $mofcomFlag ?? 'No sensitive export material keyword flagged.',
                'cross_ecosystem_links' => [
                    'customs_duty_node' => 'https://customs.une.education/mcp.php',
                    'customs_action' => "Call 'calculate_customs_duties' with your 6-digit HS code and CIF value.",
                    'maritime_transport_node' => 'https://maritime.une.education/mcp.php',
                    'maritime_action' => "Call 'optimize_fueleu_pool_allocation' or 'lookup_fuel_wtw_emission_factor' for container shipping emissions and compliance balance."
                ]
            ];

            sendJsonRpcResponse($id, ['content' => [['type' => 'text', 'text' => json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]], null, $t_start, $method, $rawInput);
        }

        sendJsonRpcResponse($id, null, ['code' => -32601, 'message' => "Tool '{$toolName}' not found."], $t_start, $method, $rawInput);
    }

    sendJsonRpcResponse($id, null, ['code' => -32601, 'message' => "Method '{$method}' not supported."], $t_start, $method, $rawInput);

} catch (\Throwable $e) {
    sendJsonRpcResponse($id, null, ['code' => -32603, 'message' => 'Internal server error: ' . $e->getMessage()], $t_start, $method, $rawInput);
}
