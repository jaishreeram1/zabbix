<?php
// Zabbix API credentials
$ZABBIX_URL = 'https://zabbixdemo.goapl.com/api_jsonrpc.php';
$AUTH_TOKEN = '225e57e579ba9c1a03e79d2a46129a69bab501c8b0611f055f616e30c9d691c5';  // Replace with your actual Auth token

// Function to call Zabbix API
function zabbix_api_call($method, $params = null) {
    global $ZABBIX_URL, $AUTH_TOKEN;

    $headers = ['Content-Type: application/json'];
    $data = [
        'jsonrpc' => '2.0',
        'method'  => $method,
        'params'  => $params ?? [],
        'id'      => 1,
        'auth'    => $AUTH_TOKEN,
    ];

    $ch = curl_init($ZABBIX_URL);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    $response_data = json_decode($response, true);

    return $response_data;
}

// Fetch all host groups from Zabbix
function fetch_all_host_groups() {
    return zabbix_api_call('hostgroup.get', [
        'output' => ['groupid', 'name'],
    ])['result'];
}

// Fetch host IDs for a specific host group
function fetch_host_ids($group_id) {
    return zabbix_api_call('host.get', [
        'output' => ['hostid', 'name'],
        'groupids' => $group_id,
        'filter' => ['status' => 0],  // Only enabled hosts
    ])['result'];
}

// Fetch memory utilization data for a specific host within a time period
function fetch_memory_utilization($hostid, $time_from, $time_till) {
    // Fetch the item IDs for memory utilization (Total memory and Available memory)
    $memory_items_response = zabbix_api_call('item.get', [
        'output' => ['itemid', 'key_'],
        'hostids' => $hostid,
        'search' => ['key_' => 'vm.memory.size'],
    ]);

    if (empty($memory_items_response['result'])) {
        return null;
    }

    $total_memory = null;
    $available_memory = null;
    foreach ($memory_items_response['result'] as $item) {
        if ($item['key_'] == 'vm.memory.size[total]') {
            $total_memory = $item['itemid'];  // Total memory item
        } elseif ($item['key_'] == 'vm.memory.size[available]') {
            $available_memory = $item['itemid'];  // Available memory item
        }
    }

    if (is_null($total_memory) || is_null($available_memory)) {
        return null;
    }

    // Fetch the trend data for Total memory and Available memory
    $trend_data = [];
    $trend_data = array_merge($trend_data, zabbix_api_call('trend.get', [
        'itemids' => $total_memory,
        'time_from' => $time_from,
        'time_till' => $time_till,
    ])['result']);
    $trend_data = array_merge($trend_data, zabbix_api_call('trend.get', [
        'itemids' => $available_memory,
        'time_from' => $time_from,
        'time_till' => $time_till,
    ])['result']);

    if (empty($trend_data)) {
        return null;
    }

    // Calculate utilization in GB
    $total_values = [];
    $available_values = [];
    foreach ($trend_data as $record) {
        if ($record['itemid'] == $total_memory) {
            $total_values[] = floatval($record['value_avg']) / 1024 / 1024 / 1024;  // Convert to GB
        } elseif ($record['itemid'] == $available_memory) {
            $available_values[] = floatval($record['value_avg']) / 1024 / 1024 / 1024;  // Convert to GB
        }
    }

    // Calculate the memory utilization (Total - Available)
    $utilization_values = [];
    foreach ($total_values as $key => $total) {
        if (isset($available_values[$key])) {
            $utilization_values[] = $total - $available_values[$key];
        }
    }

    if (empty($utilization_values)) {
        return null;
    }

    return [
        'total' => round(array_sum($total_values) / count($total_values), 2),  // Total Memory in GB
        'min' => round(min($utilization_values), 2),  // Min Memory Utilization in GB
        'avg' => round(array_sum($utilization_values) / count($utilization_values), 2),  // Avg Memory Utilization in GB
        'max' => round(max($utilization_values), 2),  // Max Memory Utilization in GB
    ];
}

// Function to format time in min:sec format
function format_time_taken($seconds) {
    $minutes = floor($seconds / 60);
    $seconds = $seconds % 60;
    return "$minutes:$seconds";
}

// Main logic for report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $time_from = isset($_POST['time_from']) ? strtotime($_POST['time_from']) : null;
    $time_till = isset($_POST['time_till']) ? strtotime($_POST['time_till']) : null;
    $hostgroup_ids = isset($_POST['hostgroup_ids']) ? $_POST['hostgroup_ids'] : [];

    if (empty($hostgroup_ids)) {
        echo "<p class='error'>Error: At least one host group ID must be selected.</p>";
    } else {
        $start_time = microtime(true);

        $summary_data = [];

        // Fetch host group details
        foreach ($hostgroup_ids as $group_id) {
            $host_ids = fetch_host_ids($group_id);

            // Fetch memory utilization data for each host
            foreach ($host_ids as $host) {
                $memory_utilization = fetch_memory_utilization($host['hostid'], $time_from, $time_till);

                if ($memory_utilization) {
                    if (!isset($summary_data[$group_id])) {
                        $summary_data[$group_id] = [
                            'group_name' => $host['name'],  // Store group name
                            'hosts' => [],
                        ];
                    }

                    $summary_data[$group_id]['hosts'][] = [
                        'hostname' => $host['name'],
                        'memory_utilization' => $memory_utilization,
                    ];
                }
            }
        }

        $generation_time = microtime(true) - $start_time;

        // Display the report
        echo "<div class='report-container'>";
        echo "<h1 class='widget-header'>Memory Utilization Report</h1>";
        
        // Left-aligned details
        echo "<div class='left-aligned-details'>";
        echo "<p><strong>Generated On:</strong> " . date('Y-m-d H:i:s') . "</p>";
        echo "<p><strong>Start Date:</strong> " . date('Y-m-d', $time_from) . "</p>";
        echo "<p><strong>End Date:</strong> " . date('Y-m-d', $time_till) . "</p>";
        echo "<p><strong>Host Groups Selected:</strong> ";
        $hostgroups = fetch_all_host_groups();
        $selected_groups = [];
        foreach ($hostgroups as $hostgroup) {
            if (in_array($hostgroup['groupid'], $hostgroup_ids)) {
                $selected_groups[] = $hostgroup['name'];
            }
        }
        echo implode(", ", $selected_groups);
        echo "</p></div>";

        // Loop through the host groups and display the data
        foreach ($summary_data as $group_id => $data) {
            echo "<h2 class='section-header'>{$data['group_name']}</h2>";
            echo "<table class='availability-table'>
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>Total Memory (GB)</th>
                            <th>Min Utilization (GB)</th>
                            <th>Avg Utilization (GB)</th>
                            <th>Max Utilization (GB)</th>
                        </tr>
                    </thead>
                    <tbody>";

            foreach ($data['hosts'] as $host) {
                echo "<tr>
                        <td>{$host['hostname']}</td>
                        <td>{$host['memory_utilization']['total']} GB</td>
                        <td>{$host['memory_utilization']['min']} GB</td>
                        <td>{$host['memory_utilization']['avg']} GB</td>
                        <td>{$host['memory_utilization']['max']} GB</td>
                    </tr>";
            }

            echo "</tbody></table><br />";
        }

        echo "<div class='action-btn-container'>
                <button class='action-btn' onclick='history.back()'>Back</button>
                <button class='action-btn' onclick='window.print()'>Print</button>
              </div>";
        echo "</div>";
    }
} else {
    // If the form is not submitted, show the input form
    $hostgroups = fetch_all_host_groups();
    ?>
    <div class="widgets-container">
        <div class="widget">
            <h2 class="widget-header">Generate Memory Utilization Report</h2>
            <form method="POST">
                <label for="time_from">Start Date:</label><br>
                <input type="date" id="time_from" name="time_from" value=""><br><br>

                <label for="time_till">End Date:</label><br>
                <input type="date" id="time_till" name="time_till" value=""><br><br>

                <label for="hostgroup_ids">Select Host Groups:</label><br>
                <select id="hostgroup_ids" name="hostgroup_ids[]" multiple="multiple" style="width: 100%;" required>
                    <?php foreach ($hostgroups as $hostgroup): ?>
                        <option value="<?php echo $hostgroup['groupid']; ?>"><?php echo $hostgroup['name']; ?></option>
                    <?php endforeach; ?>
                </select><br><br>

                <input type="submit" value="Generate" class="generate-btn">
            </form>
        </div>
    </div>
    <?php
}
?>

<!-- CSS Stylesheet -->
<style>
    body {
        font-family: 'Arial', sans-serif;
        background-color: #f4f6f9;
        margin: 0;
        padding: 0;
        display: flex;
        justify-content: center;
        align-items: center;
	
        width: 100%;
    }

    .widgets-container {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 90vh; /* Adjusted to move upwards */
    }

    .widget {
        background-color: white;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        border-radius: 8px;
        width: 350px;
        max-width: 100%;
        margin: 0 auto;
    }

    .widget-header {
        color: midnightblue;
        font-size: 22px;
        font-weight: bold;
        text-align: center;
        margin-bottom: 20px;
        margin-left: -10px;
    }

    label {
        display: block;
        margin-bottom: 5px;
        font-size: 16px;
        color: #333;
    }

    input[type="date"],
    select {
        width: 100%;
        padding: 10px;
        font-size: 14px;
        margin-bottom: 20px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }

    .generate-btn {
        width: 100%;
        background-color: midnightblue;
        color: white;
        padding: 12px;
        border: none;
        cursor: pointer;
        border-radius: 5px;
        font-size: 16px;
        margin-top: 10px;
    }

    .generate-btn:hover {
        background-color: #0056b3;
    }

    .report-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        background-color: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        margin: 5px;
        width: 80%;
        margin: 0 auto;
        margin-top: 10px;
    }

    .left-aligned-details {
        text-align: left;
        width: 100%;
        padding-left: 10px;
    }

    .left-aligned-details p {
        margin: 5px 0;
        font-size: 16px;
        color: #333;
    }

    .left-aligned-details strong {
        width: 150px;
        display: inline-block;
    }

    .availability-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .availability-table th:first-child, .availability-table td:first-child {
        text-align: left;
        padding-left: 10px;
        width: 60%;
    }

    .availability-table th, .availability-table td {
        padding: 10px;
        border: 1px solid #ddd;
    }

    .availability-table th {
        background-color: midnightblue;
        color: white;
    }

    .availability-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }

    .action-btn-container {
        display: flex;
        justify-content: center;
        gap: 10px;
        margin-top: 30px;
    }

    .action-btn {
        padding: 10px 20px;
        background-color: midnightblue;
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        cursor: pointer;
    }

    .action-btn:hover {
        background-color: #0056b3;
    }
</style>

<!-- Include Select2 for dropdown styling -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('#hostgroup_ids').select2({
            placeholder: "Select host groups",
            allowClear: true
        });
    });
</script>

