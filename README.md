# OpenEMR Sinch Conversations Module

A HIPAA-compliant, omnichannel patient communication module for OpenEMR using the Sinch Conversations API.

## Features

- **📱 Multi-Channel Messaging**: SMS, WhatsApp, RCS, MMS, Viber - all through a unified API
- **🔐 HIPAA-Compliant**: Approved message templates with no PHI in SMS
- **✅ Consent Management**: Track patient opt-ins and honor opt-out requests
- **🤖 Keyword Handling**: Automatic HELP/STOP/START response system
- **📅 Appointment Features**:
  - High-compliance appointment reminders (portal links only)
  - Telehealth appointment links
  - Missed appointment follow-up
  - Pre-visit instructions
- **💬 Portal Notifications**:
  - New secure message alerts
  - Test results available notifications
- **💰 Billing Communications**:
  - Statement ready notifications
  - Balance due reminders (no amounts in SMS)
- **🏥 Wellness & Prevention**:
  - Annual wellness visit reminders
  - Public health campaigns (flu shots, screenings)
- **📢 Operational Messages**:
  - Office closure alerts
  - Emergency updates
- **📊 Post-Visit Surveys**: Patient feedback collection
- **🔄 Polling Architecture**: No webhooks needed - simple local development

## Requirements

- OpenEMR 7.0.0 or later
- PHP 8.2 or later
- MySQL 5.7 or later / MariaDB 10.2 or later
- **Sinch Conversations API account with BAA** (see Legal Requirements below)

## Installation

### Important Note About Uninstall/Reinstall

**OpenEMR does not automatically drop module tables on uninstall.** If you need to reinstall the module cleanly:

```bash
# Option 1: Via Docker (if using Docker environment)
docker compose exec -T mysql mariadb -uroot -proot openemr < cleanup.sql

# Option 2: Via phpMyAdmin or MySQL client
# Connect to your OpenEMR database and run cleanup.sql
```

This will drop all module tables and allow a clean reinstallation.

### Via Composer (Recommended)

1. Navigate to your OpenEMR installation directory
2. Install the module via Composer:
   ```bash
   composer require opencoreemr/oce-module-sinch-conversations
   ```

3. Log into OpenEMR as an administrator
4. Navigate to **Administration > Modules > Manage Modules**
5. Find "OpenCoreEMR Sinch Conversations" in the list and click **Register**
6. Click **Install**
7. Click **Enable**

### Manual Installation

1. Download the latest release
2. Extract to `interface/modules/custom_modules/oce-module-sinch-conversations` (relative to your OpenEMR root directory)
3. Follow steps 3-7 from the Composer installation

## Sinch Provisioning CLI

The module includes a CLI tool for managing Sinch configuration programmatically (apps, webhooks, channels). This is useful for:
- Initial setup and inspection
- Multi-tenant configurations
- CI/CD automation
- Avoiding manual dashboard configuration

**Quick Start:**

```bash
# Inspect your Sinch configuration
export SINCH_PROJECT_ID="your-project-id"
export SINCH_APP_ID="your-app-id"
export SINCH_API_KEY="your-api-key"
export SINCH_API_SECRET="your-api-secret"

./cli.php sinch:inspect

# List all apps
./cli.php sinch:app:list

# Create a webhook
./cli.php sinch:webhook:create "https://your-openemr.com/webhook.php"
```

**📖 Full documentation:** See [CLI-PROVISIONING.md](./CLI-PROVISIONING.md) for complete CLI documentation and API client usage.

## Configuration

1. Navigate to **Administration > Globals > OpenCoreEMR Sinch Conversations Module**
2. Configure the following settings:
   - **Sinch Project ID**: Your Sinch project ID
   - **Sinch App ID**: Your Sinch app ID
   - **API Key**: Your Sinch API key (will be encrypted)
   - **API Secret**: Your Sinch API secret (will be encrypted)
   - **API Region**: Select your preferred region (default: 'us')
   - **Default Channel**: SMS, WhatsApp, or RCS
   - **Clinic Name**: Your clinic name (appears in all messages)
   - **Clinic Phone**: Your main clinic phone number

3. Save the settings
4. Test the connection using the "Test API Connection" button

## Usage

### Patient Opt-In

**CRITICAL:** Patients must opt in before receiving any messages.

**Methods for collecting consent:**

1. **Patient Portal**: Add opt-in checkbox during portal registration
2. **In-Person**: Collect consent on intake forms
3. **Web Form**: Dedicated consent form on clinic website

**When a patient opts in**, the system will automatically send the required confirmation:

```
Example Clinic: You have successfully opted-in to receive alerts from
Example Clinic. Msg&Data rates may apply. Msg freq varies. Reply HELP for help.
Reply STOP to unsubscribe at any time.
```

### Sending Messages

#### Individual Messages

Messages are sent automatically based on triggers:

- **Appointment Reminder**: Sent 24 hours before appointment (configurable)
- **Telehealth Link**: Sent 15 minutes before telehealth appointment
- **Test Results**: When results are finalized in OpenEMR
- **New Portal Message**: When provider sends secure message
- **Missed Appointment**: When patient marked as no-show

Or manually from the module interface:
1. Navigate to **Modules > Sinch Conversations**
2. Click **Send Message**
3. Select patient
4. Choose template
5. Preview and send

#### Batch Messages

For announcements to multiple patients:

1. Navigate to **Modules > Sinch Conversations**
2. Click **Batch Messages**
3. Select patient cohort (all patients, age range, etc.)
4. Choose template (office closure, wellness campaign, etc.)
5. Preview and schedule/send

### Viewing Conversations

1. Navigate to **Modules > Sinch Conversations**
2. View inbox with all conversations
3. Click **Refresh** to check for new patient replies
4. Click conversation to view full thread
5. Reply to patient messages

### Keyword Responses (HELP, STOP)

The system automatically handles patient keyword responses:

| Keyword | Action | Response |
|---------|--------|----------|
| **STOP** | Opt-out patient | "You have been unsubscribed from our text notifications..." |
| **START** | Re-subscribe patient | "You have been re-subscribed to text notifications..." |
| **HELP** | Provide assistance | "Text notifications from {{ clinic_name }}. For assistance, call..." |

**Supported opt-out keywords:** STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT
**Supported opt-in keywords:** START, UNSTOP, SUBSCRIBE

## Legal Requirements & Disclaimers

### ⚠️ Business Associate Agreement (BAA) Required

**IMPORTANT:** This module uses the Sinch Conversations API to send text messages to patients. **You MUST have a Business Associate Agreement (BAA) with Sinch** before using this module in production with patient data.

**OpenCoreEMR has a BAA with Sinch** and has received compliance approval for the message templates included in this module.

**If you are not using this module through OpenCoreEMR**, you must:

1. ✅ **Execute your own BAA with Sinch** before sending any patient messages
2. ✅ **Submit your message templates** to Sinch for compliance review
3. ✅ **Obtain approval** before using templates in production
4. ✅ **Maintain proper consent records** for all patients

**To establish a BAA with Sinch:**
- **OpenCoreEMR Customers:** Contact support@opencoreemr.com
- **Other Organizations:** Contact Sinch at https://www.sinch.com/contact/

### TCPA & HIPAA Compliance

Before sending any SMS messages, you **MUST**:

#### 1. Obtain Prior Express Written Consent

You must obtain **prior express written consent** from patients before sending any text messages. This consent must:

- ✅ Be in writing (electronic or paper)
- ✅ Clearly state the patient agrees to receive text messages
- ✅ Include the phone number where they'll receive messages
- ✅ Specify the types of messages they'll receive (appointments, billing, etc.)
- ✅ State that message and data rates may apply
- ✅ Include opt-out instructions

**Example Consent Language:**

> I consent to receive text message notifications from [Clinic Name] at the phone number provided above. I understand that these messages may include appointment reminders, test result notifications, billing reminders, and other healthcare-related communications. I understand that message and data rates may apply, and message frequency varies. I can opt out at any time by replying STOP.

#### 2. Document Consent

You must maintain records of:
- ✅ Patient signature or electronic agreement
- ✅ Date of consent
- ✅ Phone number provided
- ✅ Types of messages agreed to
- ✅ Method of consent (web form, in-person, etc.)

#### 3. Never Include PHI in SMS

**Standard SMS is not encrypted.** You must **NEVER** include Protected Health Information such as:

- ❌ Diagnoses
- ❌ Treatment details
- ❌ Specific test results
- ❌ Medication names
- ❌ Account balances (amounts)
- ❌ Specific appointment reasons

**Instead:** Use the approved templates that direct patients to the secure patient portal.

✅ **Good:** "Your test results are available. Log in to view: [portal link]"
❌ **Bad:** "Your cholesterol test came back at 245 mg/dL"

#### 4. Honor Opt-Out Requests Immediately

- ✅ Process STOP keywords within seconds
- ✅ Confirm opt-out with confirmation message
- ✅ Never send marketing messages to opted-out patients
- ✅ May still send critical transactional messages (e.g., appointment cancellations)

#### 5. Identify Yourself in Every Message

Every message must start with your clinic name:

✅ **Good:** "Example Clinic: Your appointment is tomorrow..."
❌ **Bad:** "Your appointment is tomorrow..."

#### 6. Include Opt-Out Instructions

Every message must include opt-out instructions:

✅ **Required:** "Reply STOP to opt-out"

### Required Initial Opt-In Confirmation

When a patient first opts in, you **MUST** send this confirmation message:

```
{{ clinic_name }}: You have successfully opted-in to receive alerts from
{{ clinic_name }}. Msg&Data rates may apply. Msg freq varies. Reply HELP
for help. Reply STOP to unsubscribe at any time.
```

This message is **required by carriers (CTIA) and the TCPA**. The system sends it automatically when a patient opts in.

### Approved Message Templates

All message templates included in this module have been reviewed and approved by Sinch for use by OpenCoreEMR under our BAA. These templates are:

- ✅ HIPAA-compliant (when used with proper consent)
- ✅ TCPA-compliant
- ✅ Carrier-compliant (CTIA guidelines)

**Template Categories:**
1. Initial Opt-In Confirmation (required)
2. Appointment Reminders (high-compliance version)
3. Telehealth Appointment Links
4. Missed Appointment Follow-Up
5. Pre-Visit Instructions
6. Portal Notifications (new messages, test results)
7. Billing Reminders (statement ready, balance due)
8. Preventive Care / Wellness Reminders
9. Public Health Announcements (flu shots, etc.)
10. Office Closure / Emergency Updates
11. Post-Visit Feedback Surveys

**If you create custom templates**, you **MUST** submit them to Sinch for compliance review before using them in production.

See [`TEMPLATE-IMPLEMENTATION-PLAN.md`](./TEMPLATE-IMPLEMENTATION-PLAN.md) for complete template details.

### Message Timing Restrictions

**Best practices for send times:**
- ✅ 8 AM - 9 PM local time (patient's timezone)
- ❌ Avoid late night or early morning messages
- ✅ Exception: Emergency/urgent notifications (office closures, safety alerts)

### No Warranty / Use at Your Own Risk

This open-source software is provided **"AS IS"** without warranty of any kind, express or implied. You are **solely responsible** for ensuring your use of this module complies with:

- HIPAA Privacy and Security Rules
- TCPA (Telephone Consumer Protection Act)
- State-specific privacy laws (e.g., California CMIA, Texas HB300)
- Carrier regulations (CTIA Best Practices)
- Any other applicable laws and regulations

**STRONGLY RECOMMENDED:** Consult with legal counsel experienced in healthcare compliance before deploying this module in a production environment.

## Security

- 🔐 **Encrypted Credentials**: API keys encrypted using OpenEMR's CryptoGen
- 🔒 **TLS/SSL**: All API communications use TLS 1.2+
- 📝 **Audit Logging**: All messages logged for compliance
- ✅ **Access Control**: OpenEMR ACL integration
- 🛡️ **CSRF Protection**: All forms protected against CSRF attacks
- 🔑 **Patient Consent**: Verified before every message send

## Architecture

### Polling-Based (No Webhooks)

This module uses a **polling architecture** instead of webhooks:

**Benefits:**
- ✅ Simple local development (no ngrok needed)
- ✅ Works identically locally and in production
- ✅ User-controlled refresh (click "Refresh" button)
- ✅ Easier testing and debugging
- ✅ No external dependencies

**How it works:**
1. Provider clicks "Refresh" or loads inbox
2. Module polls Sinch API for new messages
3. Stores new messages in local database
4. Displays updated inbox

See [`POLLING-ARCHITECTURE.md`](./POLLING-ARCHITECTURE.md) for detailed implementation.

## Development

### Development Taskfile

This module includes a **Taskfile** for common development tasks:

```bash
# Install Task (if not already installed)
# macOS
brew install go-task

# Linux
sh -c "$(curl --location https://taskfile.dev/install.sh)" -- -d -b ~/.local/bin

# Show all available tasks
task --list

# Quick start
task setup                    # Complete setup (install, prebuild, start)
task dev:start                # Start Docker environment
task dev:port                 # Get OpenEMR URL
task module:cleanup           # Clean database for reinstall
task check                    # Run all code quality checks
```

**Common tasks:**
- `task dev:start` - Start Docker environment
- `task dev:logs` - View live logs
- `task dev:port` - Get OpenEMR access URL
- `task module:cleanup` - Drop tables for clean reinstall
- `task db:shell` - Access database
- `task check` - Run pre-commit checks
- `task --list` - See all available tasks

### Docker Development Environment

Quick setup for local module development:

```bash
# 1. Clone and install dependencies
git clone https://github.com/opencoreemr/oce-module-sinch-conversations.git
cd oce-module-sinch-conversations
composer install

# 2. Pre-build OpenEMR (optional but recommended - saves 5-10 min)
cd vendor/openemr/openemr
composer install --no-dev
npm install --legacy-peer-deps && npm run build
cd ../../..

# 3. Start Docker environment
docker compose up -d --wait

# 4. Get the port and open in browser
docker compose port openemr 80
# Visit http://localhost:PORT and login with admin/pass
```

**All local changes are immediately reflected** - no rebuild needed! See [`docker/README.md`](./docker/README.md) for details.

#### Common Docker Commands

```bash
# View live logs from OpenEMR container
docker compose logs -f openemr

# View MySQL logs
docker compose logs -f mysql

# Check container status
docker compose ps

# Execute commands in running OpenEMR container
docker compose exec openemr bash
docker compose exec openemr php -v

# Access MariaDB database
docker compose exec mysql mariadb -h mysql -uroot -proot openemr

# Stop environment (keeps data)
docker compose down

# Stop and remove all data (fresh start)
docker compose down -v

# Restart a specific service
docker compose restart openemr

# View phpMyAdmin (get port first)
docker compose port phpmyadmin 80
# Visit http://localhost:PORT
```

**Note:** We use `docker compose exec` to run commands in already-running containers:
- Fast execution (no container startup overhead)
- No entrypoint conflicts
- Works with running services

#### Troubleshooting Docker Issues

**Container won't start:**
```bash
# Check logs for errors
docker compose logs openemr

# Remove everything and start fresh
docker compose down -v
docker compose up -d --wait
```

**OpenEMR installer keeps running:**
```bash
# The installer creates a sites/default/sqlconf.php file
# If this file exists, installer is complete
docker compose exec openemr ls -la /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php
```

**Changes not showing up:**
```bash
# PHP changes should be instant (OPCACHE_OFF=1)
# If not, restart Apache:
docker compose restart openemr
```

**Database issues:**
```bash
# Access database directly
docker compose exec mysql mariadb -uroot -proot openemr

# View module tables
docker compose exec mysql mariadb -uroot -proot -e "SHOW TABLES LIKE 'oce_sinch%'" openemr

# Export database
docker compose exec mysql mariadb-dump -uroot -proot openemr > backup.sql

# Import database
docker compose exec -T mysql mariadb -uroot -proot openemr < backup.sql
```

**Port conflicts:**
```bash
# If port 80 is already in use, Docker will assign a random port
# Find the assigned port:
docker compose port openemr 80

# Or specify a custom port in compose.yml:
# ports:
#   - "8080:80"  # Use localhost:8080
```

#### Module Development in Docker

**Installing module in OpenEMR:**
1. Access OpenEMR at `http://localhost:PORT`
2. Login as admin/pass
3. Navigate to **Administration > Modules > Manage Modules**
4. Find "oce-module-sinch-conversations" and click **Register**
5. Click **Install** then **Enable**

**Testing module changes:**
- Edit any PHP/Twig file in your local directory
- Refresh browser - changes appear immediately
- No container rebuild needed!

**Running tests inside container:**
```bash
# Access container shell
docker compose exec openemr bash

# Inside container, navigate to module directory
cd /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oce-module-sinch-conversations

# Run pre-commit checks (includes syntax check, PHPCS, PHPStan, etc.)
pre-commit run -a

# Run individual checks if needed
composer phpcs    # Code style
composer phpstan  # Static analysis
```

**Debugging:**
```bash
# View Apache error logs
docker compose logs -f openemr | grep -i error

# View PHP errors from log files
docker compose exec openemr tail -f /var/log/apache2/error.log
```

### PHI-Free Development

**60-70% of this module can be developed without any PHI:**

- ✅ API integration layer
- ✅ Template system
- ✅ Keyword handlers (HELP/STOP)
- ✅ UI framework (with mock data)
- ✅ Database schema
- ✅ Polling service
- ✅ Testing (with synthetic data)

**Testing without patient data:**
- Send test messages to **your own phone number**
- Use **Sinch test/sandbox environment**
- Mock data in UI: "Jane Doe", "+15555551234"

See [`PHI-ANALYSIS.md`](./PHI-ANALYSIS.md) for complete development approach.

### Running Pre-Commit Checks

```bash
# Run all code quality checks
pre-commit run -a

# This runs:
# - PHP syntax check
# - PHP_CodeSniffer (PSR-12)
# - PHPStan (level 8)
# - Rector
# - Composer Require Checker
```

### Architecture Patterns

This module follows OpenEMR's modern architecture:

- **Controllers** in `src/Controller/` handle HTTP requests
- **Services** in `src/Service/` contain business logic
- **Twig templates** in `templates/` for all HTML
- **Symfony HTTP Foundation** for Request/Response
- **Custom exceptions** for error handling
- **QueryUtils** for all database operations

See [`CLAUDE.md`](./CLAUDE.md) for complete architecture guide.

## Documentation

- [`TEMPLATE-IMPLEMENTATION-PLAN.md`](./TEMPLATE-IMPLEMENTATION-PLAN.md) - Complete template guide
- [`POLLING-ARCHITECTURE.md`](./POLLING-ARCHITECTURE.md) - Polling implementation details
- [`PHI-ANALYSIS.md`](./PHI-ANALYSIS.md) - PHI-free development approach
- [`copilot-breakdown.md`](./copilot-breakdown.md) - Feature roadmap and implementation phases
- [`CLAUDE.md`](./CLAUDE.md) - Module architecture patterns

## Support

### OpenCoreEMR Customers

For support with this module:
- **Email:** support@opencoreemr.com
- **Phone:** Contact your account representative

For Sinch BAA or compliance questions:
- **Email:** support@opencoreemr.com

### Other Users

For technical issues with this module:
- **GitHub Issues:** https://github.com/opencoreemr/oce-module-sinch-conversations/issues

For Sinch BAA or compliance:
- **Sinch Contact:** https://www.sinch.com/contact/

For legal/compliance advice:
- **Consult your own legal counsel**

## Contributing

This is a complex project with strict compliance requirements. Contributions should:

- ✅ Follow OpenEMR module architecture patterns (see `CLAUDE.md`)
- ✅ Pass all pre-commit checks (PHPCS, PHPStan, Rector)
- ✅ Include proper error handling with custom exceptions
- ✅ Use Symfony Request/Response objects
- ✅ Use QueryUtils for all database operations
- ✅ Include comprehensive tests
- ✅ Never include PHI in test data
- ✅ Document all API integrations

**Before submitting a PR:**
1. Run `pre-commit run -a` and fix all issues
2. Test with Sinch sandbox/test environment
3. Update documentation if adding features
4. Ensure no PHI in code, comments, or commits

## License

GNU General Public License v3.0 or later

## Credits

Developed by **OpenCoreEMR Inc**

- Website: https://opencoreemr.com
- Email: info@opencoreemr.com

## Disclaimer

This module is designed to help healthcare organizations communicate with patients via text messaging in a HIPAA-compliant manner. However, **the ultimate responsibility for compliance rests with the organization using this software**.

You must:
- ✅ Have appropriate legal agreements in place (BAA with Sinch)
- ✅ Obtain and document patient consent
- ✅ Train staff on proper usage
- ✅ Monitor for compliance
- ✅ Consult with legal counsel

**OpenCoreEMR Inc provides this software "as is" and makes no warranties regarding compliance with any laws or regulations.**
