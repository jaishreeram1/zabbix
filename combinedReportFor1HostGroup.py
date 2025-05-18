import requests
from reportlab.lib.pagesizes import letter
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.platypus import SimpleDocTemplate, Paragraph, Table, TableStyle, Image, PageBreak
from reportlab.lib.units import inch
from datetime import datetime, timedelta
import smtplib
from email.mime.multipart import MIMEMultipart
from email.mime.base import MIMEBase
from email import encoders
from email.mime.text import MIMEText
import os
from email.mime.image import MIMEImage
import time
from reportlab.platypus import Spacer
import json
from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import letter
from reportlab.lib.units import inch
from reportlab.platypus import SimpleDocTemplate

# Zabbix API endpoint and Auth token
zabbix_url = 'https://monitoring.goapl.com/api_jsonrpc.php'
auth_token = 'd936851e2280983005f81969dd2e8c00017f22461e88b372964a1648443944a6'  # Replace with actual token

EMAIL_ADDRESS = 'monitoring@goapl.com'  # Sender email
RECIPIENTS = ['niraj.rokade@goapl.com']  # Recipient emails
SMTP_SERVER = 'goapl-com.mail.protection.outlook.com'  # Replace with actual SMTP server
SMTP_PORT = 25  # Use the appropriate port (25, 587, or 465 for SSL)


# def add_watermark(canvas_obj, doc):
#     try:
#         # Save the current state of the canvas
#         canvas_obj.saveState()

#         # Path to the watermark image (ensure the path is correct)
#         watermark_image = "/home/ubuntu/logo.png"  # Ensure this is a transparent PNG image

#         # Define page dimensions and set watermark scale
#         page_width, page_height = letter
#         img_width = page_width * 0.3  # Adjust size to be smaller and centered
#         img_height = img_width  # Maintain aspect ratio

#         # Position at the center of the page
#         x_position = (page_width - img_width) / 2
#         y_position = (page_height - img_height) / 2

#         # Draw the image (try without mask for better visibility)
#         canvas_obj.drawImage(watermark_image, x=x_position, y=y_position, width=img_width, height=img_height)

#         # Restore the canvas state
#         canvas_obj.restoreState()
#         print("Watermark added successfully.")

#     except Exception as e:
#         # Print the exception message for debugging
#         print(f"Failed to add watermark: {e}")

# # Build the PDF with the watermark on each page
#     doc = SimpleDocTemplate(pdf_file, pagesize=letter)
#     doc.build(elements, onFirstPage=add_watermark, onLaterPages=add_watermark)



def add_logo_top_center(canvas_obj, doc):
    try:
        # Save the current state of the canvas
        canvas_obj.saveState()

        # Define the path to your logo image
        logo_image = "/home/ubuntu/logo.jpeg"  # Update with the actual path

        # Define dimensions and position
        page_width, page_height = letter
        img_width = 2 * inch  # Set the desired width for the logo
        img_height = inch      # Set the desired height for the logo

        # Center horizontally at the top of the page
        x_position = (page_width - img_width) / 2  # Center horizontally
        y_position = page_height - img_height - 0.05 * inch  # Top with a small margin

        # Draw the logo image
        canvas_obj.drawImage(logo_image, x=x_position, y=y_position, width=img_width, height=img_height, mask='auto')

        # Restore the canvas state
        canvas_obj.restoreState()
        print("Logo added at top center successfully.")

    except Exception as e:
        # Print the exception message for debugging
        print(f"Failed to add logo at top center: {e}")








def zabbix_api_call(method, params=None):
    data = {
        'jsonrpc': '2.0',
        'method': method,
        'params': params or {},
        'id': 1,
        'auth': auth_token,
    }
    try:
        response = requests.post(zabbix_url, json=data).json()
        # Check if 'result' key exists in response
        if 'result' not in response:
            print("Error in API response:", response)  # Log the entire response
            raise KeyError("API response does not contain 'result'")
        return response
    except Exception as e:
        print(f"Error in API call to {method}: {e}")
        return {}

def get_all_host_groups():
    response = zabbix_api_call('hostgroup.get', {
        'output': ['groupid', 'name'],
    })
    return {group['groupid']: group['name'] for group in response['result']}

def get_host_ids(group_id):
    hosts_response = zabbix_api_call('host.get', {
        'output': ['hostid', 'name', 'os'],
        'groupids': group_id,
        'filter': {'status': 0},  # Only enabled hosts
    })
    return [(host['hostid'], host['name'], host.get('os', 'Unknown OS')) for host in hosts_response['result']]

def get_cpu_load(hostid, time_from, time_till):
    trend_response = zabbix_api_call('item.get', {
        'output': ['itemid'],
        'hostids': hostid,
        'search': {'key_': 'system.cpu.load[all,avg15]'},
    })

    if not trend_response['result']:
        return {'min': 0, 'avg': 0, 'max': 0}

    itemid = trend_response['result'][0]['itemid']
    trend_data = zabbix_api_call('trend.get', {
        'itemids': itemid,
        'time_from': time_from,
        'time_till': time_till,
    })

    min_values, max_values, avg_values = [], [], []
    for data in trend_data['result']:
        if 'value_avg' in data:
            avg_values.append(float(data['value_avg']))
        if 'value_min' in data:
            min_values.append(float(data['value_min']))
        if 'value_max' in data:
            max_values.append(float(data['value_max']))

    if not avg_values:
        return {'min': 0, 'avg': 0, 'max': 0}

    return {
        'min': round(min(min_values), 2) if min_values else 0,
        'avg': round(sum(avg_values) / len(avg_values), 2),
        'max': round(max(max_values), 2) if max_values else 0,
    }

def get_cpu_utilization(hostid, time_from, time_till):
    trend_response = zabbix_api_call('item.get', {
        'output': ['itemid'],
        'hostids': hostid,
        'tags': [{'tag': 'item', 'value': 'cpu_utilization'}]
    })

    if not trend_response['result']:
        return {'total': 0, 'min': 0, 'avg': 0, 'max': 0}

    itemid = trend_response['result'][0]['itemid']
    trend_data = zabbix_api_call('trend.get', {
        'itemids': itemid,
        'time_from': time_from,
        'time_till': time_till,
    })

    min_values, max_values, avg_values = [], [], []
    for data in trend_data['result']:
        if 'value_min' in data:
            min_values.append(float(data['value_min']))
        if 'value_max' in data:
            max_values.append(float(data['value_max']))
        if 'value_avg' in data:
            avg_values.append(float(data['value_avg']))

    if not min_values or not max_values:
        return {'total': 0, 'min': 0, 'avg': 0, 'max': 0}

    return {
        'min': round(min(min_values), 2),
        'avg': round(sum(avg_values) / len(avg_values), 2) if avg_values else 0,
        'max': round(max(max_values), 2)
    }

def get_memory_utilization(hostid, time_from, time_till, os_type):
    # Fetch total and used memory items
    total_memory_response = zabbix_api_call('item.get', {
        'output': ['itemid'],
        'hostids': hostid,
        'tags': [{'tag': 'item', 'value': 'total_memory'}]
    })

    used_memory_response = zabbix_api_call('item.get', {
        'output': ['itemid'],
        'hostids': hostid,
        'tags': [{'tag': 'item', 'value': 'used_memory'}]
    })

    if not total_memory_response.get('result') or not used_memory_response.get('result'):
        return {'total': 0, 'min': 0, 'avg': 0, 'max': 0}

    itemid_total = total_memory_response['result'][0]['itemid']
    itemid_used = used_memory_response['result'][0]['itemid']

    # Fetch memory trends
    total_memory_trend = zabbix_api_call('trend.get', {
        'itemids': itemid_total,
        'time_from': time_from,
        'time_till': time_till,
    })

    used_memory_trend = zabbix_api_call('trend.get', {
        'itemids': itemid_used,
        'time_from': time_from,
        'time_till': time_till,
    })

    total_memory = float(total_memory_trend['result'][0]['value_avg']) if total_memory_trend.get('result') else 0
    used_memory_values = [float(data['value_avg']) for data in used_memory_trend.get('result', [])]

    if not used_memory_values or total_memory == 0:
        return {'total': total_memory / (1024 ** 3), 'min': 0, 'avg': 0, 'max': 0}

    # Calculate min, avg, max of used memory in percentage
    min_used_values, max_used_values, avg_used_values = [], [], []
    for data in used_memory_trend['result']:
        if 'value_min' in data:
            min_used_values.append(float(data['value_min']) / total_memory * 100)
        if 'value_max' in data:
            max_used_values.append(float(data['value_max']) / total_memory * 100)
        if 'value_avg' in data:
            avg_used_values.append(float(data['value_avg']) / total_memory * 100)

    return {
        'total': round(total_memory / (1024 ** 3), 2),  # Total memory in GB
        'min': round(min(min_used_values), 2) if min_used_values else 0,
        'avg': round(sum(avg_used_values) / len(avg_used_values), 2) if avg_used_values else 0,
        'max': round(max(max_used_values), 2) if max_used_values else 0
    }


# Disk Utilization functions
def get_disk_info(auth_token, host_id):
    headers = {'Content-Type': 'application/json-rpc'}
    data = {
        'jsonrpc': '2.0',
        'method': 'item.get',
        'params': {
            'output': ['itemid', 'name', 'lastvalue'],
            'hostids': host_id,
            'search': {
                'key_': 'vfs.fs.size'
            },
            'filter': {
                'state': 0
            },
            'sortfield': 'name',
            'limit': 10
        },
        'id': 3,
        'auth': auth_token
    }
    response = requests.post(zabbix_url, headers=headers, data=json.dumps(data))
    return response.json().get('result', [])

def convert_size(size_in_bytes):
    try:
        size_in_bytes = float(size_in_bytes)
        size_in_gb = size_in_bytes / (1024 ** 3)
        return f"{size_in_gb:.2f} GB" if size_in_gb < 1024 else f"{size_in_gb / 1024:.2f} TB"
    except ValueError:
        return "Invalid size"

def get_disk_utilization(hostid):
    disk_info = get_disk_info(auth_token, hostid)
    disk_data = []
    for disk in disk_info:
        if 'Space utilization' not in disk['name']:  # Filter out irrelevant data
            disk_data.append({
                'name': disk['name'],
                'allocated': convert_size(disk['lastvalue'])
            })
    return disk_data



def fetch_host_availability(hostid, time_from, time_till):
    # Fetch uptime data
    uptime_response = zabbix_api_call('item.get', {
        'output': ['itemid'],
        'hostids': hostid,
        'search': {'key_': 'icmpping'}
    })

    # Debugging uptime response
    print(f"Uptime response for host {hostid}: {uptime_response}")

    if uptime_response.get('result'):
        item_id = uptime_response['result'][0]['itemid']
        trend_data = zabbix_api_call('trend.get', {
            'itemids': item_id,
            'time_from': time_from,
            'time_till': time_till,
        })

        # Debugging trend data
        print(f"Trend data for item {item_id}: {trend_data}")

        if trend_data.get('result'):
            total_records = len(trend_data['result'])
            up_count = sum(1 for record in trend_data['result'] if float(record['value_avg']) > 0)
            down_count = total_records - up_count

            up_percentage = (up_count / total_records) * 100 if total_records else 0
            down_percentage = (down_count / total_records) * 100 if total_records else 0

            return {
                'up': round(up_percentage, 2),
                'down': round(down_percentage, 2)
            }

    # Return 0% if no data is available
    print(f"No uptime data available for host {hostid}. Returning 0% availability.")
    return {'up': 0, 'down': 0}




# Define the time period for the data query
time_from = int((datetime.now() - timedelta(days=1) - datetime(1970, 1, 1)).total_seconds())
time_till = int((datetime.now() - datetime(1970, 1, 1)).total_seconds())

# Start time for report generation
start_time = time.time()

# Retrieve all host groups
host_groups = get_all_host_groups()
summary_data = {}

# Loop through each host group to gather data
host_ids = get_host_ids(91)
group_name = "Galaxy CRM server"
for hostid, hostname, os_type in host_ids:
    cpu_load = get_cpu_load(hostid, time_from, time_till)
    cpu_utilization = get_cpu_utilization(hostid, time_from, time_till)
    memory_utilization = get_memory_utilization(hostid, time_from, time_till, os_type)
    disk_utilization = get_disk_utilization(hostid)
    host_availability = fetch_host_availability(hostid, time_from, time_till)

    if (cpu_load['min'] > 0 or cpu_load['avg'] > 0 or cpu_load['max'] > 0 or
            cpu_utilization['min'] > 0 or cpu_utilization['avg'] > 0 or cpu_utilization['max'] > 0 or
            memory_utilization['min'] > 0 or memory_utilization['avg'] > 0 or memory_utilization['max'] > 0):
    
        if group_name not in summary_data:
            summary_data[group_name] = {
                'cpu_load': [], 'cpu_utilization': [], 'memory_utilization': [], 'disk_utilization': [], 'host_availability': []
            }

        summary_data[group_name]['cpu_load'].append({
            'hostname': hostname,
            'load': cpu_load,
        })
        summary_data[group_name]['cpu_utilization'].append({
            'hostname': hostname,
            'utilization': cpu_utilization,
        })
        summary_data[group_name]['memory_utilization'].append({
            'hostname': hostname,
            'memory_utilization': memory_utilization
        })
        summary_data[group_name]['disk_utilization'].append({
            'hostname': hostname,
            'disk_info': disk_utilization
        })
        summary_data[group_name]['host_availability'].append({
        'hostname': hostname,
        'availability': host_availability
        })

# Generate PDF
pdf_file = "resource_utilization_report.pdf"
doc = SimpleDocTemplate(pdf_file, pagesize=letter, topMargin=0.5*inch, leftMargin=0.75*inch, rightMargin=0.75*inch)






  # Adjust top margin as needed
elements = []
doc.build(elements, onFirstPage=add_logo_top_center)

# Title
styles = getSampleStyleSheet()
title_style = styles['Title']
title_style.leading = 20  # Control line height for the title

# Add a spacer after the logo to move the title downward
elements.append(Spacer(1, 30))  # Adjust the second parameter (height in points) for more or less space

title = Paragraph("Zabbix Resource Utilization Report", title_style)
elements.append(title)

elements.append(Spacer(1, 12)) 


# Summary
generated_on = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
end_time = time.time()
time_taken = round(end_time - start_time, 2)


summary_style = ParagraphStyle(
    'summary_style',
    parent=styles['Normal'],
    alignment=0,  # Left alignment
    leftIndent=-20,
    spaceAfter=6,
)

# Add the summary with the specific style
summary = Paragraph(
    #f"<b>Summary</b><br/>"
    f"<b>Hostgroups:</b> {group_name}<br/>"
    f"<b>Generated on:</b> {generated_on}<br/>",
    
    summary_style
)
elements.append(summary)    


# Add CPU Load Table with Total column
# elements.append(Paragraph("<b>CPU Load</b>", ParagraphStyle(
#     'heading_style',
#     parent=styles['Heading2'],
#     leftIndent=-20  # Adjust this value to shift left
# )))
# cpu_load_table_data = [["Hostname", "Min", "Avg", "Max"]]
# for group_name, hosts in summary_data.items():
#     for host in hosts['cpu_load']:
#         total_load = round((host['load']['min'] + host['load']['avg'] + host['load']['max']) / 3, 2)
#         cpu_load_table_data.append([
#             host['hostname'],
            
#             str(host['load']['min']),
#             str(host['load']['avg']),
#             str(host['load']['max']),
#         ])

# cpu_load_table = Table(cpu_load_table_data, colWidths=[3 * inch, 1.5 * inch, 1.5 * inch, 1.5 * inch])
# cpu_load_table.setStyle(TableStyle([
#     ('BACKGROUND', (0, 0), (-1, 0), colors.midnightblue),
#     ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
#     ('ALIGN', (0, 0), (-1, -1), 'LEFT'),
#     ('BOTTOMPADDING', (0, 0), (-1, 0), 12),
#     ('BACKGROUND', (0, 1), (-1, -1), colors.white),
#     ('GRID', (0, 0), (-1, -1), 1, colors.black),
# ]))
# elements.append(cpu_load_table)

# Add CPU Utilization Table with Total column
elements.append(Paragraph("<b>CPU Utilization</b>", ParagraphStyle(
    'heading_style',
    parent=styles['Heading2'],
    leftIndent=-20  # Adjust this value to shift left
)))
cpu_utilization_table_data = [["Hostname", "Min", "Avg", "Max"]]
for group_name, hosts in summary_data.items():
    for host in hosts['cpu_utilization']:
        total_utilization = round((host['utilization']['min'] + host['utilization']['avg'] + host['utilization']['max']) / 3, 2)
        cpu_utilization_table_data.append([
            host['hostname'],
            
            str(host['utilization']['min']),
            str(host['utilization']['avg']),
            str(host['utilization']['max']),
        ])

cpu_utilization_table = Table(cpu_utilization_table_data, colWidths=[3 * inch, 1.5 * inch, 1.5 * inch, 1.5 * inch])
cpu_utilization_table.setStyle(TableStyle([
    ('BACKGROUND', (0, 0), (-1, 0), colors.midnightblue),
    ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
    ('ALIGN', (0, 0), (-1, -1), 'LEFT'),
    ('BOTTOMPADDING', (0, 0), (-1, 0), 12),
    ('BACKGROUND', (0, 1), (-1, -1), colors.white),
    ('GRID', (0, 0), (-1, -1), 1, colors.black),
]))
elements.append(cpu_utilization_table)

# Add Memory Utilization Table
elements.append(Paragraph("<b>Memory Utilization</b>", ParagraphStyle(
    'heading_style',
    parent=styles['Heading2'],
    leftIndent=-20  # Adjust this value to shift left
)))
memory_utilization_table_data = [["Hostname", "Total (GB)", "Min (%)", "Avg (%)", "Max (%)"]]

for group_name, hosts in summary_data.items():
    for host in hosts['memory_utilization']:
        memory_utilization_table_data.append([
            host['hostname'],
            str(host['memory_utilization']['total']),  # Total memory in GB
            f"{host['memory_utilization']['min']}%",  # Min utilization in %
            f"{host['memory_utilization']['avg']}%",  # Avg utilization in %
            f"{host['memory_utilization']['max']}%",  # Max utilization in %
        ])

memory_utilization_table = Table(memory_utilization_table_data, colWidths=[3.5 * inch, 1 * inch, 1 * inch, 1 * inch])
memory_utilization_table.setStyle(TableStyle([
    ('BACKGROUND', (0, 0), (-1, 0), colors.midnightblue),
    ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
    ('ALIGN', (0, 0), (-1, -1), 'LEFT'),
    ('BOTTOMPADDING', (0, 0), (-1, 0), 12),
    ('BACKGROUND', (0, 1), (-1, -1), colors.white),
    ('GRID', (0, 0), (-1, -1), 1, colors.black),
]))
elements.append(memory_utilization_table)

# Add Disk Utilization Table with word wrapping for long names
elements.append(Paragraph("<b>Disk Utilization</b>", ParagraphStyle(
    'heading_style',
    parent=styles['Heading2'],
    leftIndent=-20  # Adjust this value to shift left
)))
disk_utilization_table_data = [["Hostname", "Disk", "Allocated Size"]]
wrap_style = ParagraphStyle("wrap_style", wordWrap='CJK')  # Enable word wrapping
for group_name, hosts in summary_data.items():
    for host in hosts['disk_utilization']:
        for disk in host['disk_info']:
            disk_utilization_table_data.append([
                host['hostname'],
                Paragraph(disk['name'], wrap_style),  # Wrap long disk names
                disk['allocated']
            ])

disk_utilization_table = Table(disk_utilization_table_data, colWidths=[2.5 * inch, 4 * inch, 1.5 * inch])
disk_utilization_table.setStyle(TableStyle([
    ('BACKGROUND', (0, 0), (-1, 0), colors.midnightblue),
    ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
    ('ALIGN', (0, 0), (-1, -1), 'LEFT'),
    ('BOTTOMPADDING', (0, 0), (-1, 0), 12),
    ('BACKGROUND', (0, 1), (-1, -1), colors.white),
    ('GRID', (0, 0), (-1, -1), 1, colors.black),
]))
elements.append(disk_utilization_table)

# Add Host Availability Table
# Add Host Availability Table with debugging
# Add Host Availability Table with minimal styling for debugging
# Title for the Host Availability section
# Title for the Host Availability section
elements.append(Paragraph("<b>Host Availabilty</b>", ParagraphStyle(
    'heading_style',
    parent=styles['Heading2'],
    leftIndent=-20  # Adjust this value to shift left
)))

# Populate the Host Availability Table
host_availability_table_data = [["Hostname", "Uptime (%)", "Downtime (%)"]]
for group_name, hosts in summary_data.items():
    for host in hosts['host_availability']:
        if 'availability' in host and host['availability']:
            host_availability_table_data.append([
                host['hostname'],
                str(host['availability'].get('up', 0)),
                str(host['availability'].get('down', 0)),
            ])

# Debugging: Confirm data structure
print("Host Availability Table Data:", host_availability_table_data)

# Generate table only if data is present
if len(host_availability_table_data) > 1:
    host_availability_table = Table(host_availability_table_data, colWidths=[2.5 * inch, 4 * inch, 1.5 * inch])
    host_availability_table.setStyle(TableStyle([
        ('BACKGROUND', (0, 0), (-1, 0), colors.midnightblue),
        ('TEXTCOLOR', (0, 0), (-1, 0), colors.whitesmoke),
        ('ALIGN', (0, 0), (-1, -1), 'LEFT'),    
        ('FONTNAME', (0, 0), (-1, 0), 'Helvetica-Bold'),
        ('BOTTOMPADDING', (0, 0), (-1, 0), 12),
        ('BACKGROUND', (0, 1), (-1, -1), colors.white),
        ('GRID', (0, 0), (-1, -1), 1, colors.black),
    ]))
    elements.append(host_availability_table)
else:
    elements.append(Paragraph("No data available for Host Availability Table.", getSampleStyleSheet()['Normal']))

# Build PDF with isolated table
doc.build(elements)
print("PDF generated with Host Availability Table.")

def send_email_with_attachment(pdf_file):
    try:
        # Calculate start and end dates for the last 24 hours
        end_date = datetime.now()
        start_date = end_date - timedelta(days=1)
        formatted_start_date = start_date.strftime("%Y-%m-%d %H:%M:%S")
        formatted_end_date = end_date.strftime("%Y-%m-%d %H:%M:%S")

        # Define other details
        generated_on = end_date.strftime("%Y-%m-%d %H:%M:%S")
        host_group = "Galaxy CRM server"
        generation_time = round(time.time() - start_time, 2)

        # Define HTML content for the email body
        html_body = f"""
            <html>
                <body>
                    <h3>Galaxy Internal Business Application Resource Utilization Report Summary (CRM, Tally, TimeTracker)</h3>

                    <p><strong>Duration:</strong> From {formatted_start_date} To {formatted_end_date}</p>
                    <p><strong>Generated On:</strong> {generated_on}</p>
                    
                    <p><strong>Hostgroups:</strong>{host_group}</p>
                        <p>Please find the attached report. </p>
                    <br>
                    
                    <img src="cid:logo_image" alt="Logo" width="109" height="35" style="width:80px; height:22px;"/>
                    <p style="font-family: Calibri; font-weight: bold; color:red; font-size: 16;">Galaxy Office Automation Pvt. Ltd.</p>
                    <p style="font-size: 9; color: black;">
                        B-602, Lotus Corporate Park, Graham Firth Compound, Opp. Raheja Ridgewood, off. Western Express Highway,<br>
                        Goregaon (E), Mumbai – 400063, India.<br>
                        Tel 1800 266 2515 | URL <a href="http://www.goapl.com">www.goapl.com</a>
                    </p>
                </body>
            </html>
            """

        # Create the email message
        msg = MIMEMultipart('related')
        msg['From'] = EMAIL_ADDRESS
        msg['To'] = ', '.join(RECIPIENTS)
        msg['Subject'] = "Galaxy Internal Business Application Resource Utilization Report Summary (CRM, Tally, TimeTracker)"

        # Attach the HTML body
        msg.attach(MIMEText(html_body, 'html'))

        # Attach the PDF file as an attachment
        try:
            with open(pdf_file, "rb") as attachment:
                part = MIMEBase('application', 'octet-stream')
                part.set_payload(attachment.read())
                encoders.encode_base64(part)
                part.add_header('Content-Disposition', f"attachment; filename= {os.path.basename(pdf_file)}")
                msg.attach(part)
        except FileNotFoundError:
            print("PDF file not found.")
            return

        # Attach the logo image as a MIMEImage with CID for inline display
        try:
            with open("/home/ubuntu/logo.jpeg", "rb") as img_file:
                logo = MIMEImage(img_file.read())
                logo.add_header('Content-ID', '<logo_image>')
                logo.add_header('Content-Disposition', 'inline', filename="logo.jpeg")
                msg.attach(logo)
        except FileNotFoundError:
            print("Logo image file not found.")
            # You can choose to continue without the logo or return here.

        # Send the email
        with smtplib.SMTP(SMTP_SERVER, SMTP_PORT) as server:
            server.starttls()  # Secure the connection
            # Uncomment and add credentials if authentication is required
            # server.login('your_username', 'your_password')
            server.sendmail(EMAIL_ADDRESS, RECIPIENTS, msg.as_string())
        print(f"Email with '{pdf_file}' sent successfully to {', '.join(RECIPIENTS)}")

    except Exception as e:
        print(f"Failed to send email: {e}")

# After generating the PDF
pdf_file = "resource_utilization_report.pdf"
# Generate your report here (your existing code)...

# Send the email with the generated PDF as an attachment
send_email_with_attachment(pdf_file)


