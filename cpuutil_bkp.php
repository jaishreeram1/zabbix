<?php
// Zabbix API endpoint and Auth token
$zabbix_url = 'https://zabbixdemo.goapl.com/api_jsonrpc.php';
$auth_token = '225e57e579ba9c1a03e79d2a46129a69bab501c8b0611f055f616e30c9d691c5';  // Replace with your actual Auth token

// Function to call the Zabbix API
function zabbix_api_call($method, $params = []) {
    global $zabbix_url, $auth_token;
    
    $data = [
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => 1,
        'auth' => $auth_token,
    ];
    
    $ch = curl_init($zabbix_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Function to get all host groups
function get_all_host_groups() {
    $response = zabbix_api_call('hostgroup.get', [
        'output' => ['groupid', 'name'],
    ]);
    return $response['result'] ?? [];
}

// Function to get host IDs for a given group
function get_host_ids($group_id) {
    $response = zabbix_api_call('host.get', [
        'output' => ['hostid', 'name'],
        'groupids' => $group_id,
        'filter' => ['status' => 0],  // Only enabled hosts
    ]);
    return $response['result'] ?? [];
}

// Function to get CPU cores for a given host
function get_cpu_cores($hostid) {
    $cpu_cores_response = zabbix_api_call('item.get', [
        'output' => ['lastvalue'],
        'hostids' => $hostid,
        'search' => ['key_' => 'system.cpu.num'],
    ]);
    
    if (!empty($cpu_cores_response['result'])) {
        return (int) $cpu_cores_response['result'][0]['lastvalue'];
    }
    
    $wmi_response = zabbix_api_call('item.get', [
        'output' => ['lastvalue'],
        'hostids' => $hostid,
        'search' => ['key_' => 'wmi.get[root/cimv2,"Select NumberOfLogicalProcessors from Win32_ComputerSystem"]'],
    ]);
    
    return !empty($wmi_response['result']) ? (int) $wmi_response['result'][0]['lastvalue'] : 0;
}

// Function to get CPU utilization for a given host
function get_cpu_utilization($hostid, $time_from, $time_till) {
    $trend_response = zabbix_api_call('item.get', [
        'output' => ['itemid'],
        'hostids' => $hostid,
        'search' => ['key_' => 'system.cpu.util'],
    ]);
    
    if (empty($trend_response['result'])) {
        return ['min' => 0, 'avg' => 0, 'max' => 0];
    }

    $itemid = $trend_response['result'][0]['itemid'];
    $trend_data = zabbix_api_call('trend.get', [
        'itemids' => $itemid,
        'time_from' => $time_from,
        'time_till' => $time_till,
    ]);
    
    $avg_values = array_map(function($data) { return (float) $data['value_avg']; }, $trend_data['result']);
    $min_values = array_map(function($data) { return (float) $data['value_min']; }, $trend_data['result']);
    $max_values = array_map(function($data) { return (float) $data['value_max']; }, $trend_data['result']);

    return [
        'min' => round(min($min_values), 2),
        'avg' => round(array_sum($avg_values) / count($avg_values), 2),
        'max' => round(max($max_values), 2),
    ];
}

// Handle form submission and data retrieval
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
$selected_groups = isset($_POST['hostgroups']) ? $_POST['hostgroups'] : [];

// If form is submitted and required parameters are available
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $start_date && $end_date && !empty($selected_groups)) {
    // Convert dates to Unix timestamps
    $time_from = strtotime($start_date . ' 00:00:00');
    $time_till = strtotime($end_date . ' 23:59:59');

    // Fetch host groups based on user selection
    $host_groups = array_filter(get_all_host_groups(), function($group) use ($selected_groups) {
        return in_array($group['groupid'], $selected_groups);
    });

    $summary_data = [];

    foreach ($host_groups as $group) {
        $group_name = $group['name'];
        $group_id = $group['groupid'];
        
        // Get hosts in this group
        $host_ids = get_host_ids($group_id);

        foreach ($host_ids as $host) {
            $hostid = $host['hostid'];
            $hostname = $host['name'];

            $cpu_cores = get_cpu_cores($hostid);
            $cpu_utilization = get_cpu_utilization($hostid, $time_from, $time_till);

            if ($cpu_cores > 0 && ($cpu_utilization['min'] > 0 || $cpu_utilization['avg'] > 0 || $cpu_utilization['max'] > 0)) {
                $summary_data[$group_name][] = [
                    'hostname' => $hostname,
                    'cpu_cores' => $cpu_cores,
                    'cpu_utilization' => $cpu_utilization,
                ];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zabbix CPU Utilization Report</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f4f8;
            color: #333;
        }

        .widget-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        /* Form for selecting parameters */
        .parameter-form {
            width: 25%; /* Set width specifically for the parameter form */
            background-color: white;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        /* Form for report display */
        .report-form {
            width: 70%; /* Set a wider width for the report form */
            background-color: white;
            padding: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        h1 {
            color: midnightblue;
            font-size: 28px;
            text-align: left; /* Left aligned heading */
        }

        form {
            margin-bottom: 30px;
        }

        label {
            font-size: 16px;
            color: midnightblue;
        }

        input[type="date"],
        select,
        .select2-container--default .select2-selection--single {
            padding: 8px;
            margin-top: 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
            width: 100%;
            font-size: 14px;
            box-sizing: border-box;
        }

        button {
            padding: 15px 25px; /* Increased padding for a larger button */
            background-color: midnightblue;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px; /* Increased font size */
            cursor: pointer;
            width: 100%; /* Set button width to 100% to match form elements */
            margin-top: 15px;
        }

        button:hover {
            background-color: #003366;
        }

        /* Styling for Back and Print buttons */
        .action-btn-container {
            display: flex;
            justify-content: center;
	    margin-top: 30px;
            gap: 10px;
        }

        .action-btn {
            width: 100px; /* Set width of Back and Print buttons to 48% */
            padding: 10px 15px;
            background-color: midnightblue;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
	    cursor: center;
            text-align: center
        }

        .action-btn:hover {
            background-color: #003366;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: midnightblue;
            color: white;
        }

        .no-print {
            display: inline-block;
        }

        @media print {
            .no-print {
                display: none;
            }

            .widget-container {
                width: 100%;
                padding: 0;
            }

            h1 {
                font-size: 24px;
            }

            form,
            #generate-btn {
                display: none;
            }
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .widget-container {
                width: 95%;
            }

            input[type="date"],
            select {
                width: 100%;
            }

            h1 {
		font-size: 22px;
                text-align: center;
            }

            button {
                width: 100%;
            }

            .action-btn-container {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                margin-bottom: 10px;
            }
	}
           h2 {
	       text-align: center;
               color: midnightblue;
               font-weight: bold;
              }

    </style>
</head>
<body>

<div class="widget-container">
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <div class="parameter-form">
        <!-- Form for selecting parameters -->
        <h1>CPU Utilization Report</h1>
        
        <form id="report-form" method="POST" action="">
            <label for="start_date">Start Date:</label><br>
            <input type="date" id="start_date" name="start_date" required><br><br>

            <label for="end_date">End Date:</label><br>
            <input type="date" id="end_date" name="end_date" required><br><br>

            <label for="hostgroups">Host Groups:</label><br>
            <select name="hostgroups[]" id="hostgroups" multiple="multiple" required>
                <?php 
                $all_groups = get_all_host_groups();
                foreach ($all_groups as $group): 
                ?>
                    <option value="<?php echo $group['groupid']; ?>">
                        <?php echo $group['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>

            <button type="submit" id="generate-btn">Generate Report</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (isset($summary_data) && !empty($summary_data)): ?>
    <div class="report-form">
        <!-- Display Report if generated -->
        <div class="printable">
            <h2>CPU Utilization Report</h2>

           
            <p><strong>Start Date:</strong> <?php echo $start_date; ?></p>
            <p><strong>End Date:</strong> <?php echo $end_date; ?></p>
            <p><strong>Host Groups:</strong> <?php echo implode(', ', array_map(function($group) { return $group['name']; }, $host_groups)); ?></p>

            <?php foreach ($summary_data as $group_name => $hosts): ?>
                <h2>Host Group: <?php echo $group_name; ?></h3>

                <table>
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>CPU Cores</th>
                            <th>Min %</th>
                            <th>Avg %</th>
                            <th>Max %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hosts as $host): ?>
                            <tr>
                                <td><?php echo $host['hostname']; ?></td>
                                <td><?php echo $host['cpu_cores']; ?></td>
                                <td><?php echo $host['cpu_utilization']['min']; ?></td>
                                <td><?php echo $host['cpu_utilization']['avg']; ?></td>
                                <td><?php echo $host['cpu_utilization']['max']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>

            <div class="action-btn-container">
                <button class="action-btn no-print" onclick="window.history.back();">Back</button>
                <button class="action-btn no-print" onclick="window.print();">Print</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        $('#hostgroups').select2({
            placeholder: 'Select Host Groups',
            allowClear: true
        });

        $('#report-form').submit(function() {
            // Disable button on form submit
            $('#generate-btn').prop('disabled', true);
            $('#generate-btn').text('Generating...');
        });
    });
</script>

</body>
</html>

