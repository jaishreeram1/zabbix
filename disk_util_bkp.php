<?php
// Zabbix configuration
$ZABBIX_URL = 'https://zabbixdemo.goapl.com/api_jsonrpc.php';
$AUTH_TOKEN = '225e57e579ba9c1a03e79d2a46129a69bab501c8b0611f055f616e30c9d691c5';  // Replace with actual token

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

    return $response_data['result'] ?? [];
}

// Fetch enabled hosts from Zabbix
function fetch_enabled_hosts($hostgroup_ids = []) {
    $params = [
        'output' => ['hostid', 'name', 'status', 'groups'],
        'filter' => ['status' => 0], // Only enabled hosts
        'selectGroups' => ['name', 'groupid']   // Include group names and IDs
    ];
    
    if (!empty($hostgroup_ids)) {
        $params['groupids'] = $hostgroup_ids;  // Filter hosts by selected groups
    }
    
    return zabbix_api_call('host.get', $params);
}

// Fetch disk space info for a given host
function fetch_disk_info($hostid) {
    return zabbix_api_call('item.get', [
        'output' => ['itemid', 'key_', 'lastvalue'],
        'hostids' => $hostid,
        'search' => ['key_' => 'vfs.fs.dependent.size'],  // Search for disk space info
        'filter' => ['state' => 0],
    ]);
}

// Convert bytes to GB or TB (for size data) or percentage for used disk space
function convert_size($size_in_bytes) {
    if ($size_in_bytes <= 100 && $size_in_bytes >= 0) {
        return round($size_in_bytes, 2) . "%";  // If it's a percentage, return as is
    }

    $size_in_bytes = floatval($size_in_bytes);
    if ($size_in_bytes <= 0) {
        return "Invalid size";
    }

    $size_in_gb = $size_in_bytes / (1024 ** 3);  // Convert to GB
    if ($size_in_gb >= 1024) {
        return round($size_in_gb / 1024, 2) . " TB";  // Convert to TB if more than 1024 GB
    } else {
        return round($size_in_gb, 2) . " GB";
    }
}

// Organize disk data into used, total, pused values, and calculate free space
function organize_disk_data($disk_info) {
    $organized_data = [];

    foreach ($disk_info as $disk) {
        if (isset($disk['key_']) && isset($disk['lastvalue'])) {
            $key = $disk['key_'];
            $value = $disk['lastvalue'];

            $key_parts = explode(",", $key);
            $disk_name = isset($key_parts[0]) ? $key_parts[0] : '';
            $type = isset($key_parts[1]) ? $key_parts[1] : '';

            if (!isset($organized_data[$disk_name])) {
                $organized_data[$disk_name] = [
                    'used' => null,
                    'total' => null,
                    'pused' => null,
                    'free' => null
                ];
            }

            // Organize data by disk name (e.g., "/", "C:", "/boot")
            if (strpos($type, 'pused') !== false) {
                $organized_data[$disk_name]['pused'] = $value;
            } elseif (strpos($type, 'total') !== false) {
                $organized_data[$disk_name]['total'] = $value;
            } elseif (strpos($type, 'used') !== false) {
                $organized_data[$disk_name]['used'] = $value;
            }
        }
    }

    // Calculate free space (total - used)
    foreach ($organized_data as $disk_name => $data) {
        if (isset($data['total']) && isset($data['used'])) {
            $total_size = floatval($data['total']);
            $used_size = floatval($data['used']);
            $free_size = $total_size - $used_size;

            $organized_data[$disk_name]['free'] = $free_size;
        }
    }

    return $organized_data;
}

// Fetch hostgroups selected by the user
function fetch_selected_hostgroups($hostgroup_ids) {
    return zabbix_api_call('hostgroup.get', [
        'output' => ['groupid', 'name'],
        'groupids' => $hostgroup_ids,
    ]);
}

// Display the form to select hostgroups and date range
function display_form($hostgroups) {
    echo "<html>";
    echo "<head>";
    echo "<title>Generate Disk Utilization Report</title>";
    echo "<link href='https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' rel='stylesheet' />";
    echo "<link href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css' rel='stylesheet' />";
    echo "<style>
            body {font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex; justify-content: center; align-items: flex-start; height: 100vh; background-color: #f5f5f5; overflow: auto;}
	    .form-container {max-width: 400px; padding: 20px; background-color: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); width: 100%; margin-top: 80px; box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);}
            .form-container h2 {color: MidnightBlue; font-size: 1.8em; text-align: center; margin-bottom: 20px;}
            .form-container label {display: block; font-size: 16px; margin-bottom: 13px; display: block;}
            .form-container input[type='date'], .form-container select {width: 100%; padding: 10px; margin: 5px 0 15px; font-size: 14px; border: 1px solid #ccc; border-radius: 5px;}
            .form-container input[type='submit'] {background-color: MidnightBlue; color: white; padding: 10px; border: none; font-size: 14px; cursor: pointer; width: 100%; margin-top: 20px; border-radius: 5px;}
            .form-container input[type='submit']:hover {background-color: #4c4a8d;}
            #loadingMessage {font-size: 18px; color: MidnightBlue; display: none; text-align: center; margin-top: 20px;}
          </style>";
    echo "</head>";
    echo "<body>";

    echo "<div class='form-container'>";
    echo "<h2>Generate Disk Utilization Report</h2>";
    echo "<form id='reportForm' method='POST' action=''>";
    echo "<label for='start_date'>Start Date:</label>";
    echo "<input type='date' id='start_date' name='start_date' required>";
    echo "<label for='end_date'>End Date:</label>";
    echo "<input type='date' id='end_date' name='end_date' required>";
    
    echo "<label for='hostgroups'>Select Hostgroups:</label>";
    echo "<select name='hostgroups[]' id='hostgroups' multiple='multiple' style='width:100%;'>";
    foreach ($hostgroups as $group) {
        echo "<option value='" . $group['groupid'] . "'>" . $group['name'] . "</option>";
    }
    echo "</select>";

    echo "<input type='submit' id='generateReportButton' value='Generate Report'>";
    echo "</form>";
    echo "</div>";

    echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js'></script>";
    echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js'></script>";
    echo "<script>
            $(document).ready(function() {
                $('#hostgroups').select2();

                $('#reportForm').submit(function(event) {
                    // Change button text to 'Generating...' and disable button
                    $('#generateReportButton').val('Generating...').attr('disabled', true);
                });
            });
          </script>";

    echo "</body>";
    echo "</html>";
}

// Display the report
function display_report($hosts_data, $summary_data) {
    echo "<html>";
    echo "<head>";
    echo "<title>Disk Utilization Report</title>";
    echo "<style>
            body {font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex; justify-content: center; align-items: flex-start; background-color: #f5f5f5; overflow: auto;}
            .report-container {max-width: 1300px; padding: 20px; background-color: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2); width: 100%; margin-top: 50px;}
            h1 {color: MidnightBlue; font-size: 2em; text-align: center;}
            h2 {font-size: 1.0em; font-weight: normal; color: MidnightBlue;}
            h3 {font-size: 1.0em; font-weight: normal; margin: 10px 0;}
            .summary {margin-bottom: 20px; color: #333; font-size: 1.1em;}
            table {width: 100%; border-collapse: collapse; margin-top: 20px; border: 1px solid #ddd;}
            table, th, td {border: 1px solid #ddd;}
            th, td {padding: 10px; text-align: left;}
            th {background-color: MidnightBlue; color: white;}  /* MidnightBlue background for headers */
            .center-buttons {text-align: center; margin-top: 30px;}
            .btn {background-color: MidnightBlue; color: white; padding: 12px 20px; border: none; cursor: pointer; font-size: 16px; border-radius: 5px;}
            .btn:hover {background-color: #4c4a8d;}
            .no-print {margin-right: 10px;}
            .btn-space {margin-right: 20px;}
            @media print { .no-print { display: none; } }
          </style>";
    echo "</head>";
    echo "<body>";

    echo "<div class='report-container'>"; // Report container with shadow
    echo "<h1>Disk Utilization Report</h1>";

    // Summary
    echo "<div class='summary'>";
    echo "<h2>Generated on: " . $summary_data['generated_on'] . "</h2>";
    echo "<h3>Date Range: " . $summary_data['start_date'] . " to " . $summary_data['end_date'] . "</h3>";
    echo "<h3>Report Time: " . $summary_data['time_taken'] . " seconds</h3>";
    echo "</div>";

    // Group hosts by host group and display separate tables for selected groups only
    foreach ($summary_data['hostgroups'] as $group_name => $group_hosts) {
        if (!empty($group_hosts)) { // Display only if the group has hosts
            // Display host group name
            echo "<h1>Host Group: " . htmlspecialchars($group_name) . "</h2>";

            // Display table for each host group
            echo "<table>";
            echo "<tr><th>Host Name</th><th>Disk</th><th>Total Size</th><th>Used Size</th><th>Free Size</th><th>Used (%)</th></tr>";

            foreach ($group_hosts as $host) {
                foreach ($host['disk_data'] as $disk => $data) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($host['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($disk) . "</td>";
                    echo "<td>" . convert_size($data['total']) . "</td>";
                    echo "<td>" . convert_size($data['used']) . "</td>";
                    echo "<td>" . convert_size($data['free']) . "</td>";
                    echo "<td>" . htmlspecialchars($data['pused']) . "%</td>";
                    echo "</tr>";
                }
            }

            echo "</table><br>";
        } else {
            // No hosts found in this group
            echo "<h2>No data available for Host Group: " . htmlspecialchars($group_name) . "</h2><br>";
        }
    }

    // Footer with buttons
    echo "<div class='center-buttons no-print'>";
    echo "<button class='btn btn-space' onclick='window.history.back()'>Back</button>";
    echo "<button class='btn' onclick='window.print()'>Print</button>";
    echo "</div>";
    echo "</div>";  // Closing the shadowed container

    echo "</body>";
    echo "</html>";
}

// Main execution

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $hostgroup_ids = $_POST['hostgroups'];

    if (empty($hostgroup_ids)) {
        echo "<script>alert('Please select at least one host group.');</script>";
    } else {
        // Fetch selected hostgroups
        $hostgroups = fetch_selected_hostgroups($hostgroup_ids);

        // Fetch enabled hosts based on the selected hostgroups
        $enabled_hosts = fetch_enabled_hosts($hostgroup_ids);

        // Organize hosts data by host group
        $hosts_data_by_group = [];
        foreach ($enabled_hosts as $host) {
            $hostid = $host['hostid'];
            $disk_info = fetch_disk_info($hostid);
            $disk_data = organize_disk_data($disk_info);

            foreach ($host['groups'] as $group) {
                $group_name = $group['name'];
                if (in_array($group['groupid'], $hostgroup_ids)) {  // Display only selected hostgroups
                    if (!isset($hosts_data_by_group[$group_name])) {
                        $hosts_data_by_group[$group_name] = [];
                    }
                    $hosts_data_by_group[$group_name][] = [
                        'name' => $host['name'],
                        'disk_data' => $disk_data
                    ];
                }
            }
        }

        // Prepare summary data
        $summary_data = [
            'generated_on' => date('Y-m-d H:i:s'),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'time_taken' => round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2),  // Time taken to generate the report
            'hostgroups' => $hosts_data_by_group  // Host data grouped by hostgroup
        ];

        // Display the report
        display_report($hosts_data_by_group, $summary_data);
    }
} else {
    // Display the form to select hostgroups and date range
    $all_hostgroups = zabbix_api_call('hostgroup.get', ['output' => ['groupid', 'name']]);
    display_form($all_hostgroups);
}
?>

