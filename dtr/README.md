# Jovagne's OJT DTR - Daily Time Record System

A super-simple, personal-use-only DTR web app for OJT time tracking on localhost XAMPP. No login required, session-based tracking with optional database storage for monthly summaries.

## 🎯 Features

- **One-Click Time Recording**: AM IN, AM OUT, PM IN, PM OUT buttons with auto-capture
- **Real-time Calculations**: Automatic daily hours computation (OUT-IN difference)
- **Overtime Detection**: Red badge alert when daily hours exceed 16 hours
- **16-Hour OT Lock Feature**: Quick checkbox to mark any day as 16-hour OT (locked, no times needed)
- **Manual Time Entry**: Enter times for today or past days (up to 3 months back)
- **Past Days Support**: Fill in missed entries from previous days with date picker
- **Monthly Summary**: Total rendered hours and OT days count for current month
- **Monthly Records Table**: View all entries for current month with remarks
- **Comprehensive Monthly Report**: Detailed statistics with computed totals (total days, average hours, regular vs OT breakdown)
- **Print Report**: One-click print functionality for monthly reports
- **Session-Based**: Today's data clears when browser closes (no persistent login/storage)
- **Database Storage**: Optional MySQL storage for monthly records and history
- **Mobile-Responsive**: Bootstrap 5 mobile-first design

## 📋 Requirements

- XAMPP (PHP 8.0 or higher)
- Modern web browser (Chrome, Firefox, Edge)
- MySQL (bundled with XAMPP) - Optional for monthly view

## 🚀 Quick Setup

### 1. Copy Files to XAMPP
```
1. Copy the 'dtr' folder to: c:\xampp\htdocs\
2. Your structure should be:
   c:\xampp\htdocs\dtr\
   ├── index.php
   ├── config.php
   ├── create_table.sql
   └── README.md
```

### 2. Start XAMPP
```
1. Open XAMPP Control Panel
2. Start Apache
3. Start MySQL (for monthly summaries)
```

### 3. Create Database (Optional but Recommended)
```
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Click "Import" tab
3. Choose 'create_table.sql' file
4. Click "Go"

OR run this SQL manually:
- Create database: dtr_tracking
- Import the create_table.sql file
```

### 4. Access the App
```
Open browser and go to:
http://localhost/dtr/

That's it! Start tracking your time.
```

## 📖 How to Use

### Recording Time (Auto-Capture)
1. Click **AM IN** when you start morning shift
2. Click **AM OUT** when you end morning shift
3. Click **PM IN** when you start afternoon shift
4. Click **PM OUT** when you end afternoon shift

Buttons auto-enable in sequence - you can't skip steps!

### Manual Time Entry (For Today or Past Days)
1. **Select Date**: Choose today or any past day (up to 3 months back) today
- **Monthly Summary**: Displays cumulative hours and OT days for current month
- **Today's Log Table**: Shows today's time entries and status
- **Monthly Records Table**: Shows all entries from current month (requires database)

### Add Remarks
**For Today**: Type your task in the "Quick Remarks" field and click Save or tab out.

**For Manual Entries**: When entering times manually, use the Remarks field in the manual entry section.

**Use Cases:**
- Forgot to clock in yesterday → Select yesterday's date, enter times
- Need to fill in last week's entries → Select each date and enter
- Correct a mistake from 2 days ago → Select that date, update times
- Fill in entire month at once → Enter each day's data one by one

### View Your Hours
- **Today's Hours**: Shows real-time calculation of total hours worked today
- **Monthly Summary**: Displays cumulative hours and OT days for current month
- **Today's Log Table**: Shows today's time entries and status
- **Monthly Records Table**: Shows all entries from current month (requires database)

### View Monthly Report (Computed Totals)
When you have database records, a comprehensive monthly report appears showing:
- **Total Days Worked**: Breakdown of normal vs OT days
- **Total Hours**: Complete sum of all hours for the month
- **Average Hours/Day**: Calculated average across all working days
- **Regular Hours**: Sum of non-overtime hours
- **OT Hours**: Sum of overtime hours (highlighted in red)
- **Date Range**: First and last entry dates
- **Summary Box**: Quick overview with all key statistics
- **Print Report**: Click to print or save as PDF

### Clear Today's Record
Click the **Clear Today's Record** button to reset current day's entries. This will:
- Clear all time stamps
- Remove remarks
- Delete from database
- Cannot be undone! (Today Only)
- Today's data stored in PHP sessions
- Automatically clears when browser closes
- Perfect for personal, temporary tracking
- No login or authentication needed

### Database Storage (Monthly History)
- **Required for**: Past days entry, monthly records table, monthly summaries
- Stores all time entries with dates and remarks
- Auto-purges records older than 3 months
- Works without database (limited to today's session only)

### Manual Entry for Past Days
- Can enter times for any date within last 3 months
- Saves directly to database (database required)
- Updates monthly summary automatically
- Perfect for filling in missed days
- Month-to-month history stored in MySQL
- Auto-purges records older than 3 months
- Only for monthly summaries
- App works without database (monthly stats show 0)

### Calculations
- **Daily Hours** = (AM OUT - AM IN) + (PM OUT - PM IN)
- **Overtime** = If daily hours > 16, shows red "OT" badge
- **Monthly Total** = Sum of all days in current month
- **OT Days** = Count of days with >16 hours

## 🎨 Customization

### Change Header/Name
Edit line 190 in `index.php`:
```html
<h1><i class="bi bi-clock-history"></i> Your Name's OJT DTR</h1>
<div class="subtitle">Your Organization Name</div>
```

### Change OT Threshold (Default: 16 hours)
Edit lines 106 and 266 in `index.php`:
```php
$is_overtime = $daily_hours > 16 ? 1 : 0;  // Change 16 to your threshold
```

### Adjust Auto-Purge Period (Default: 3 months)
Edit line 21 in `config.php`:
```php
$threeMonthsAgo = date('Y-m-d', strtotime('-3 months'));  // Change -3 months
```

### Change Color Scheme
Edit the CSS section in `index.php` (lines 169-187):
```css
/* Main gradient */
body {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
```

## 🛠️ Troubleshooting

### "Connection failed" error
- Make sure MySQL is running in XAMPP
- Check if database 'dtr_tracking' exists
- Verify credentials in `config.php` (default: root/no password)

### Buttons not working
- **Database not set up** - Import `create_table.sql` to enable
- Check MySQL is running
- Verify database name is 'dtr_tracking'

### Can't enter past days
- **Database required** - Manual entry for past days needs MySQL
- Import `create_table.sql` first
- Check date is within 3 months (older dates are auto-purged)

### Manual entry page refreshes but data not showing
- Check if date is within current month
- Verify database connection is working
- Try entering today's date first to test

### Times not saving
- Check if PHP sessions are enabled
- Verify `session_start()` is working
- Check browser cookies are enabled

### Monthly summa for today** - Today's data clears on browser close
- **Database needed for history** - Past days require MySQL setup
- **Localhost only** - Not for production/public use
- **Auto-purge after 3 months** - Old records automatically deleted
- **Date limit: 3 months back** - Cannot enter data older thanracking
- Restart PHP session after database setup

## 📱 Mobile Use

Fully responsive! Access from your phone:
1. Find your computer's local IP (e.g., 192.168.1.100)
2. On phone browser: http://192.168.1.100/dtr/
3. Works best on same WiFi network

## ⚠️ Important Notes

- **Personal use only** - No multi-user support
- **No authentication** - Anyone with link can access
- **Session-based** - Data clears on browser close
- **Localhost only** - Not for production/public use
- **No backup** - Old records auto-delete after 3 months

## 🔒 Privacy

- No personal data collected or stored beyond temp session
- No external API calls
- All data stays on your local machine
- No tracking or analytics

## 📄 License

Free for personal use. No warranty provided.

## 👨‍💻 Author

Created for Jovagne's OJT at Luna LGU

---

**Last Updated**: March 2026  
**Version**: 1.0  
**Enter past day | Manual Entry → Select date → Enter times |
| Fill missed week | Manual Entry → Enter each day's data |
| View month | Check "This Month's Records" table |
| Reset today | Clear Today's Record button |
| Check OT | Look for red OT badge |
| See all entries | Scroll to Monthly Records tabl
---

## 🆘 Quick Reference

| Feature | Action |
|---------|--------|
| Start tracking | Click AM IN |
| End shift | Click PM OUT |
| Log 16hr OT day | Manual Entry → Check "OT Day" → Save |
| Enter past day | Manual Entry → Select date → Enter times |
| Fill missed week | Manual Entry → Enter each day |
| View report | Scroll to Monthly Report section |
| Print report | Click "Print Report" button |
| Reset today | Clear Today's Record button |
| View month | Check "This Month's Records" table |
| Check OT | Look for red OT badge |

Happy time tracking! 🎉
