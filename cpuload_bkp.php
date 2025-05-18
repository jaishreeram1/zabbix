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

// Fetch CPU load data for a specific host within a time period
function fetch_cpu_load($hostid, $time_from, $time_till) {
    // Fetch the item ID for CPU load
    $cpu_item_response = zabbix_api_call('item.get', [
        'output' => ['itemid'],
        'hostids' => $hostid,
        'search' => ['key_' => 'system.cpu.load[all,avg15]'],
    ]);

    if (empty($cpu_item_response['result'])) {
        return null;
    }

    $itemid = $cpu_item_response['result'][0]['itemid'];

    // Fetch the trend data for CPU load
    $trend_data = zabbix_api_call('trend.get', [
        'itemids' => $itemid,
        'time_from' => $time_from,
        'time_till' => $time_till,
    ])['result'];

    if (empty($trend_data)) {
        return null;
    }

    // Extract the CPU load values
    $values = array_map(function ($record) {
        return floatval($record['value_avg']);
    }, $trend_data);

    if (empty($values)) {
        return null;
    }

     $min_values = array_map(function ($record) {
        return floatval($record['value_min']);
    }, $trend_data);

    $max_values = array_map(function ($record) {
        return floatval($record['value_max']);
    }, $trend_data);


    return [
        'min' => round(min($min_values), 2),  // Minimum load
        'avg' => round(array_sum($values) / count($values), 2),  // Average load
        'max' => round(max($max_values), 2),  // Maximum load
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

            // Fetch CPU load data for each host
            foreach ($host_ids as $host) {
                $cpu_load = fetch_cpu_load($host['hostid'], $time_from, $time_till);

                if ($cpu_load) {
                    if (!isset($summary_data[$group_id])) {
                        $summary_data[$group_id] = [
                            'group_name' => $host['name'],  // Store group name
                            'hosts' => [],
                        ];
                    }

                    $summary_data[$group_id]['hosts'][] = [
                        'hostname' => $host['name'],
                        'cpu_load' => $cpu_load,
                    ];
                }
            }
        }

        $generation_time = microtime(true) - $start_time;

        // Display the report
        echo "<div class='report-container'>";  // Adjusted width for report
        echo "<h1>CPU Load Report</h1>";
        echo "<p><strong>Generated On:</strong> " . date('Y-m-d H:i:s') . "</p>";
        //echo "<p><strong>Time Taken:</strong> " . format_time_taken($generation_time) . "</p>";

        // Summary of the parameters used for generating the report
        //echo "<h3>Report Parameters:</h3>";
        echo "
                <p><strong>Start Date:</strong> " . date('Y-m-d', $time_from) . "</p>
                <p><strong>End Date:</strong> " . date('Y-m-d', $time_till) . "</p>
                <p><strong>Host Groups Selected:</strong> ";
        $hostgroups = fetch_all_host_groups();
        $selected_groups = [];
        foreach ($hostgroups as $hostgroup) {
            if (in_array($hostgroup['groupid'], $hostgroup_ids)) {
                $selected_groups[] = $hostgroup['name'];
            }
        }
        echo implode(", ", $selected_groups);
        echo "</p>";

        // Loop through the host groups and display the data
        foreach ($summary_data as $group_id => $data) {
            echo "<h2 class='section-header'>{$data['group_name']}</h2>";
            echo "<table class='cpu-load-table'>
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>Min Load</th>
                            <th>Avg Load</th>
                            <th>Max Load</th>
                        </tr>
                    </thead>
                    <tbody>";

            foreach ($data['hosts'] as $host) {
                echo "<tr>
                        <td>{$host['hostname']}</td>
                        <td>{$host['cpu_load']['min']}%</td>
                        <td>{$host['cpu_load']['avg']}%</td>
                        <td>{$host['cpu_load']['max']}%</td>
                    </tr>";
            }

            echo "</tbody></table><br />";
        }

        echo "<div class='buttons'>
                <button class='back-btn' onclick='history.back()'>Back</button>
                <button class='print-btn' onclick='window.print()'>Print</button>
              </div>";
        echo "</div>";
    }
} else {
    // If the form is not submitted, show the input form
    $hostgroups = fetch_all_host_groups();
    ?>
    <div class="widget-container">
        <h2>Generate CPU Load Report</h2>
        <form method="POST">
            <label for="time_from">Start Date:</label><br>
            <input type="date" id="time_from" name="time_from" required><br><br>

            <label for="time_till">End Date:</label><br>
            <input type="date" id="time_till" name="time_till" required><br><br>

            <label for="hostgroup_ids">Select Host Groups:</label><br>
            <select id="hostgroup_ids" name="hostgroup_ids[]" multiple="multiple" required style="width: 100%;">
                <?php foreach ($hostgroups as $hostgroup): ?>
                    <option value="<?php echo $hostgroup['groupid']; ?>"><?php echo $hostgroup['name']; ?></option>
                <?php endforeach; ?>
            </select><br><br>

            <input type="submit" value="Generate Report">
        </form>
    </div>
    <?php
}
?>

<!-- CSS Stylesheet -->
<style>
    body {
        font-family: Arial, sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f4f4f4;
        color: #333;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh; /* Center the form vertically and horizontally */
    }

    .widget-container {
        width: 350px; /* Width for the first form */
        margin: 0 auto;
        padding: 30px 20px;
        background-color: white;
        border-radius: 10px;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    .report-container {
        width: 1300px; /* Increased width for the report */
        margin: 20px auto;
        padding: 30px;
        background-color: white;
        border-radius: 10px;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        text-align: center;
    }

    h1, h2, h3 {
        color: midnightblue;
    }

    label {
        display: block;
        font-size: 16px;
        margin-bottom: 8px;
        color: #333;
        text-align: left;
    }
    p {
       text-align: left;
      }

    input[type="date"], 
    select {
        width: 100%; /* Make the input fields fill the width */
        padding: 10px;
        margin-bottom: 20px;
        font-size: 16px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }

    input[type="submit"] {
        width: 100%;
        padding: 12px;
        background-color: midnightblue;
        color: white;
        border: none;
        cursor: pointer;
        font-size: 18px;
        border-radius: 4px;
    }

    input[type="submit"]:hover {
        background-color: darkblue;
    }

    .section-header {
        font-size: 24px;
        margin-bottom: 20px;
    }

    .cpu-load-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }

    .cpu-load-table th, .cpu-load-table td {
        padding: 10px;
        border: 1px solid #ddd;
        text-align: left;
    }

    .cpu-load-table th {
        background-color: midnightblue;
        color: white;
    }

    .cpu-load-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }

    .cpu-load-table tr:hover {
        background-color: #f1f1f1;
    }

    .error {
        color: red;
    }

    .buttons {
        margin-top: 20px;
    }

    .buttons button {
        padding: 10px 20px;
        background-color: midnightblue;
        color: white;
        border: none;
        cursor: pointer;
        font-size: 16px;
        margin: 10px;
    }

    .buttons button:hover {
        background-color: darkblue;
    }

    /* Hide buttons when printing */
    @media print {
        .buttons {
            display: none;
        }
    }
</style>

<!-- Include Select2 CDN -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        // Initialize Select2 for the host group select box
        $('#hostgroup_ids').select2();
    });
</script>

