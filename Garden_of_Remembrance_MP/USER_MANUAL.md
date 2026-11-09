APPENDIX – USER MANUAL
GARDEN OF REMEMBRANCE MEMORIAL PARK CEMETERY INFORMATION MANAGEMENT SYSTEM (CMIS)

This user manual provides step-by-step instructions for using the Garden of Remembrance Memorial Park Cemetery Information Management System (CMIS). The system automates memorial lot reservations, installment payment calculations, digital GCash transactions, interactive cemetery mapping with GPS grave location, deceased records management, ownership claims, and property transfer processing. It is designed for three types of users: Public Visitors / Guests, Registered Customers / Plot Owners, and Administrators.

1. SYSTEM REQUIREMENTS
________________________________________
The Garden of Remembrance Memorial Park CMIS is a responsive, web-based application. No software installation is required. To access and use the system, prepare the following:
• Any device — desktop personal computer, laptop, tablet, or smartphone (Android or iOS)
• An updated web browser: Google Chrome, Microsoft Edge, Mozilla Firefox, or Apple Safari
• A stable internet connection for real-time map navigation, reservation validation, and payment proof submission
• An active, valid email address and Philippine mobile number for account verification, transaction receipts, and system alerts

2. ACCESSING THE SYSTEM
________________________________________
Step 1: Open your web browser.
Step 2: Type the Garden of Remembrance Memorial Park URL into the address bar (e.g., http://localhost/Garden_of_Remembrance_MP/ or the official domain name) and press Enter.
Step 3: The Public Home Page will load. From here, you can browse memorial park information, search deceased burial locations, view available sections, or choose to Log In or Register.
If the page does not load, verify your local server or internet connection and refresh the browser. Contact the cemetery administration office if the issue persists.

Screenshot: Home Page / Public Landing Interface

3. CREATING A CUSTOMER ACCOUNT (SIGN UP)
________________________________________
Visitors who wish to reserve memorial plots, make payments, track installment balances, or claim prior ownership must create a customer account. Only clients can self-register. Administrative accounts are securely created and assigned by the system administrator.

Step 1: Personal Information
• On the navigation bar, click Register (or navigate to register.php).
• Enter your First Name, Middle Name (optional), and Last Name.
• Enter your active Philippine Mobile Number (e.g., 0917XXXXXXX or +639XXXXXXXXX).
• Enter your Complete Residential Address.
• Click Continue.

Step 2: Account Credentials & Security
• Enter your Email Address. This email will serve as your unique login ID and receive system notifications, booking approvals, and payment receipts.
• Create a strong Password. It must be at least 8 characters in length and include a combination of uppercase letters, lowercase letters, numbers, and special symbols.
• Re-enter your password in the Confirm Password field to ensure accuracy.
• Click the Create Account button.

Step 3: Email Verification
• An automated email containing a 6-digit verification code or activation link will be sent to your registered email address via PHPMailer.
• Check your inbox (and Spam/Junk folder if not immediately visible).
• Enter the 6-digit code on the Account Verification screen (verify.php) and click Verify Account.
• Upon successful verification, your account is activated, and you will be redirected to the Login page.

Screenshot: Customer Registration Page
Screenshot: Email Verification Screen (verify.php)

4. LOGGING IN
________________________________________
Step 1: Click the Login button from the top navigation menu (login.php).
Step 2: Enter your registered Email Address.
Step 3: Enter your Password.
Step 4: Click the Log In button.
Step 5: The system authenticates your credentials and directs you to the appropriate interface:
• Registered Customer / Plot Owner → Customer Dashboard (user_dashboard.php)
• System Administrator → Admin Management Portal (dashboard.php)

If Login Fails:
• Double-check that your email address is spelled correctly without extra spaces.
• Ensure the Caps Lock key is turned off before typing your password.
• If your account has not yet been verified, click Resend Verification Code on the login page.
• If you forgot your password, click Forgot Password?, enter your registered email address, and follow the password reset link sent to your inbox.

Screenshot: Login Page (login.php)


PART B – CUSTOMER / PLOT OWNER GUIDE
________________________________________

5. NAVIGATING THE CUSTOMER DASHBOARD
________________________________________
After logging in, customers are welcomed into the Customer Dashboard (user_dashboard.php). The top navigation bar and responsive mobile drawer provide quick access to all client features:
• Dashboard — Displays a summary of your active plot reservations, certified owned lots, recent payments, and quick action shortcuts.
• Booking — Launches the online plot reservation modal to select sections, view pricing, and reserve available memorial plots.
• Cemetery Map — Opens the high-resolution interactive cemetery map with real-time plot availability and GPS grave locator.
• Plot Transfer — Allows you to submit and track requests to transfer plot ownership to another person or family member.
• My Profile — Allows you to update your contact details, upload a profile picture, and update account security settings.
• Notification Bell — Alerts you in real-time when a reservation is approved, a payment is verified, or an installment deadline approaches.
• Logout — Safely ends your active session and returns to the home page.

Screenshot: Customer Dashboard Overview

6. RESERVING AND BOOKING A MEMORIAL PLOT
________________________________________
Customers can browse, select, and reserve memorial plots online across Sections A through J.

Step 1: Click Booking from the top navigation bar or click Book Reservation on your dashboard.
Step 2: Choose your desired Cemetery Section (e.g., Section A - Standard Lawn, Section B - Memorial Gardens, Section C - Quiet Meadow, Section E - Corner & Prime Lawn).
Step 3: Select an available plot. The system displays plot details:
• Lot Code and Number
• Plot Classification (Standard Lawn, Premium Corner Plot, Garden Estate)
• Current Status (Only Green / Available plots can be selected)
Step 4: Choose your preferred Payment Option:
• Full Cash Payment — Settle the total plot price in full.
• Installment Plan — Select a flexible amortization schedule (12, 24, 36, 48, or 60 months).
Step 5: The system displays the automated computation:
• Total Lot Price
• Required 20% Downpayment (e.g., ₱17,000 for Regular Lawn; ₱17,800 for Premium Lawn)
• Monthly Amortization amount (for installment plans)
Step 6: Optionally select an Interment Fee Package (Promo Package, Standard Package, or Senior Citizen / PWD discounted rate).
Step 7: Review the reservation summary and click Proceed to Payment. Your plot is placed on temporary hold pending payment confirmation.

Screenshot: Plot Reservation Modal & Lot Selection
Screenshot: Payment Plan & Amortization Calculator

7. PAYMENT PROCESSING VIA GCASH & PROOF SUBMISSION
________________________________________
The system provides a seamless payment process with integrated GCash QR code generation and payment verification.

Step 1: On the Payment Checkout page (gcash_gateway.php), review the breakdown of charges (Plot Reservation Deposit or Monthly Installment).
Step 2: The screen displays:
• The official Garden of Remembrance Memorial Park Merchant Name
• The official registered GCash Mobile Number
• A dynamic, high-resolution GCash QR Code
Step 3: Open your GCash application on your mobile phone:
• Tap the QR button and scan the QR code displayed on your screen, OR
• Use Send Money / Express Send to transfer the exact amount to the displayed cemetery GCash mobile number.
Step 4: Confirm and complete the transaction in GCash. Save or take a clear screenshot of the GCash Transaction Confirmation Receipt. Take note of the GCash Reference Number (e.g., 100284759302).
Step 5: On the CMIS Payment Confirmation form (upload_payment_proof.php):
• Enter the GCash Reference Number.
• Specify the exact Amount Paid.
• Select the Payment Date.
• Click Choose File and upload the screenshot image of your GCash receipt (JPG, PNG, or JPEG).
Step 6: Click Submit Payment Proof. The status of your reservation changes to "Payment Under Verification".

Screenshot: GCash Payment Gateway & Scannable QR Code
Screenshot: Payment Proof Upload Screen

8. VIEWING INVOICES AND PAYMENT SCHEDULES
________________________________________
Step 1: From your dashboard, locate the My Reservations or Payment Schedules section.
Step 2: For confirmed payments, click the Print Invoice / View Receipt button.
Step 3: A printable, official digital invoice (print_invoice.php) opens showing:
• Official Invoice Number and Transaction Reference
• Client Name, Contact Information, and Address
• Plot Location details (Section, Block, Lot, Plot Number)
• Payment Breakdown (Total Price, Downpayment, Balance Due, Monthly Amortization)
• System QR Code for digital authentication
Step 4: Click Print or Save as PDF for your personal legal records.

Screenshot: Official Digital Invoice & Receipt View

9. REQUESTING TO ADD ANOTHER DECEASED TO AN EXISTING GRAVE
________________________________________
Clients can request to add another deceased loved one to an existing family grave plot in accordance with cemetery policies.

Step 1: From your dashboard or menu, select your existing grave plot.
Step 2: Enter the details of the deceased person to be added:
• Full Name of Deceased
• Date of Birth and Date of Death
• Relationship to the primary plot owner
Step 3: Review the interment requirements and click Submit Request.
Step 4: The cemetery Admin and Staff will review the plot capacity and request details. Once approved, the record will be updated in the system.

Screenshot: Add Deceased to Existing Grave Form

10. REQUESTING THE TRANSFER OF REMAINS
________________________________________
Clients can submit a request to transfer the remains of a deceased loved one from their current plot to a new destination plot.

Step 1: Click Transfer of Remains from the navigation menu (transfer_requests.php).
Step 2: Click New Transfer Request.
Step 3: Select the deceased record you wish to transfer.
Step 4: The system automatically verifies if the required 8-to-10-year skeletal decomposition interval policy has been met since the original interment date.
Step 5: Select the new destination Section and Plot.
Step 6: Enter the reason for the transfer request.
Step 7: Click Submit Transfer Request.
Step 8: Track the status of your request (Pending, Approved, or Rejected) on the transfer requests tracking table.

Screenshot: Transfer of Remains Request Form

11. USING THE INTERACTIVE CEMETERY MAP & GRAVE LOCATOR
________________________________________
Step 1: Click Map from the top navigation bar (map.php).
Step 2: Browsing the Cemetery Layout:
• Use your mouse wheel or pinch-to-zoom on touch devices to zoom in and out.
• Click and drag to pan across the entire memorial park.
• Use the Section Filter dropdown to quickly jump to any section (Section A to J).
Step 3: Searching for a Deceased Relative's Grave:
• Type the first name or last name of the deceased in the Grave Search bar.
• Select the person from the search suggestion list.
• The map smoothly zooms in, centers, and highlights the exact grave plot.
• A popup displays the deceased person's full name, date of birth, date of passing, interment date, and section location.
Step 4: On mobile devices with GPS enabled, tap Get Directions to view walking paths and distance from your current location to the grave.

Screenshot: Interactive Cemetery Map with Grave Locator
Screenshot: Plot Information Popup Window

12. UPDATING YOUR PROFILE
________________________________________
Step 1: Click My Profile from the navigation menu (profile.php).
Step 2: Update your personal details:
• First Name, Middle Name, Last Name
• Mobile Number and Complete Residential Address
• Profile Picture — Click Choose File to upload a square JPG or PNG photo
Step 3: To change your password:
• Enter your Current Password
• Enter your New Password (following security guidelines)
• Confirm your New Password
Step 4: Click Save Changes. A green confirmation banner will appear upon updating.

Screenshot: Customer Profile Management Page


PART C – ADMINISTRATOR GUIDE
________________________________________

13. NAVIGATING THE ADMINISTRATOR DASHBOARD
________________________________________
The System Administrator has complete oversight over cemetery operations, records, and financial transactions. Logging in with an administrator account opens the central Admin Dashboard (dashboard.php).

The navigation bar and sidebar drawer provide access to:
• Dashboard — Real-time analytics, inventory metrics, pending action counts, and quick management links.
• Booking — Review, approve, reject, or manually record plot reservations and cash walk-ins.
• Map — Full cemetery layout management, plot status modification, and GPS calibration.
• Logs — Complete immutable system audit trail tracking all actions, logins, and database alterations.
• Sales / Reports — Financial collection summaries, amortization tracking, and exportable revenue reports.
• Transfers — Review, authenticate, and approve plot ownership transfer submissions.
• Claims Review — Verify proof of purchase documents and approve plot ownership claims.
• Guest Requests — Process reservation inquiries submitted by public visitors.
• Profile — Admin account management and password security.
• About / Settings — System information, mail server configuration, and cemetery announcements.

Screenshot: Administrator Dashboard & KPI Summary Cards

14. MANAGING BOOKINGS & RESERVATIONS
________________________________________
Step 1: Click Booking from the top navigation bar or go to dashboard.php?open=booking.
Step 2: The reservations management table displays all client bookings with status badges (Pending, Confirmed, Cancelled, Completed).
Step 3: To review a pending reservation:
• Click the View Details / Action button on the reservation row.
• Inspect the customer information, selected plot, payment terms, and uploaded GCash payment proof.
• Cross-reference the GCash Reference Number and amount with your merchant account statement.
Step 4: To confirm the booking:
• Click Approve / Confirm Booking.
• The system automatically marks the plot status as "Reserved" in the database.
• An automated confirmation email and digital invoice notification are sent to the client.
Step 5: To reject an invalid booking:
• Click Reject Booking.
• Enter a clear reason (e.g., "Invalid GCash reference number", "Unclear payment screenshot").
• The reservation is cancelled, and the plot is immediately restored to "Available" status.

Screenshot: Admin Booking Management Table
Screenshot: Reviewing Payment Proof Modal

15. HANDLING GUEST RESERVATION REQUESTS
________________________________________
Step 1: Click Guest Requests in the top navigation bar (guest_reservations.php).
Step 2: The table lists all plot reservation inquiries submitted by unauthenticated website visitors.
Step 3: Review the guest's contact number, email address, chosen section/plot, and memorial preferences.
Step 4: Available Actions:
• Contact Guest — Reach out via phone or email to assist with memorial arrangements.
• Convert to Booking — Create an official booking record once payment or valid identification has been secured.
• Mark as Completed / Archive — Keep the active inquiries list organized.

Screenshot: Guest Reservation Requests Table

16. CEMETERY MAP AND PLOT MANAGEMENT
________________________________________
Step 1: Click Map (map.php) from the navigation bar.
Step 2: Managing Plot Details:
• Click any plot on the interactive map grid.
• View or edit the plot status (Available, Reserved, Occupied, Maintenance).
• Designate special plot characteristics (Regular Lawn, Corner Premium Plot, Estate).
• Click Update Plot to apply changes in real time.
Step 3: Review Section Availability:
• Use the section filter to inspect plot layout and vacancies across operational sections (Sections A to F).

Screenshot: Plot Editor Modal on Map
Screenshot: Cemetery Map Management View

17. MANAGING DECEASED RECORDS & INTERMENT
________________________________________
Step 1: Click Add Deceased from the dashboard or select an occupied plot on the map (add_deceased.php).
Step 2: Complete the Deceased Record form:
• Full Name of Deceased (First, Middle, Last)
• Date of Birth and Date of Death
• Date and Time of Interment / Burial Ceremony
• Exact Plot Location (Section, Block, Lot, Plot Number)
• Next of Kin / Contact Person Name and Phone Number
• Epitaph or Memorial Remarks
Step 3: Click Save Deceased Record.
Step 4: The plot status automatically updates to "Occupied" (maroon/red marker), and the burial record is published to the public grave search directory.

Screenshot: Add Deceased / Interment Form

18. MANAGING MULTI-INTERMENT & CEMETERY SERVICES
________________________________________
Step 1: Admin and Staff can view incoming client requests to add a deceased loved one to an existing grave.
Step 2: Inspect the existing plot record to verify plot occupancy capacity.
Step 3: Check the deceased details (Name, Date of Passing, and relationship).
Step 4: Click Approve to register the additional deceased person to the plot, or Reject if the plot has reached maximum capacity.

Screenshot: Multi-Interment Review Interface

19. REVIEWING TRANSFER OF REMAINS REQUESTS
________________________________________
Step 1: Click Transfer of Remains from the navigation bar (transfer_requests.php).
Step 2: Review incoming transfer requests submitted by clients.
Step 3: Inspect the request details:
• Current plot location and proposed destination plot.
• Verification that the 8-to-10-year skeletal decomposition interval has elapsed since the original burial date.
• Stated reason for the transfer.
Step 4: Click Approve Transfer:
• The system automatically updates the deceased record to the new plot location.
• The destination plot status is set to Occupied.
• If the previous plot is now vacant, its status updates to Available.
• An automated email notification is sent to the client.

Screenshot: Transfer Requests Review Table

20. SALES TRACKING & FINANCIAL REPORTS
________________________________________
Step 1: Click Sales / Reports from the top navigation bar (sales_reports.php).
Step 2: The financial dashboard presents:
• Gross Sales Revenue and Total Downpayments Collected
• Monthly Amortization Collections Breakdown
• Outstanding Receivables / Unpaid Balances
Step 3: Filtering Reports:
• Filter by Date Range (Today, This Month, This Quarter, Custom Dates).
• Filter by Section (Sections A to J) or Payment Method (GCash, Cash Walk-in).
Step 4: Click Export to CSV / Excel or Print Report to generate formal accounting and audit documentation.

Screenshot: Sales & Financial Reports Dashboard

21. AUDIT LOGS & SYSTEM SETTINGS
________________________________________
• Activity Logs (logs.php): View an immutable record of every user action. Each entry captures Timestamp, User Name, Role, IP Address, Action Type (e.g., Booking Approved, Plot Edited, User Logged In), and Description. Use the search bar to filter by date or username.
• System Announcements (admin_announcement_modal.php): Publish public alerts regarding visiting hours, All Saints' Day guidelines, or office holiday closures.
• Mail Settings (admin_mail_settings.php): Configure SMTP server settings, port, encryption (TLS/SSL), and sender email credentials to ensure smooth delivery of verification codes and payment receipts.

Screenshot: System Audit Activity Logs (logs.php)

22. PRE-LAUNCH READINESS CHECKLIST
________________________________________
Before opening the portal for public reservations and client use, the administrator should verify:
• Database connection is active and configured correctly in connection.php
• Official pricing config in config/pricing_policy.php matches current park lot rates
• GCash account name and mobile number in config/payment_settings.php are up to date
• SMTP email credentials in admin_mail_settings.php are tested and sending emails reliably
• All 10 cemetery sections (A to J) and plot coordinates are calibrated on the map
• A test customer account has been registered, verified, and used to perform a test reservation flow

Confirm the complete customer transaction flow works:
Register → Verify Email → Login → Select Plot → Pay via GCash → Upload Proof → Admin Approves → View Invoice

If this complete flow executes without errors, the system is fully operational and ready for live production use.

23. TROUBLESHOOTING
________________________________________
Problem | What to Do
Cannot log in | Verify your email and password spelling. Make sure Caps Lock is off. If your account is unverified, use the resend verification link.
Did not receive verification email | Check your Spam or Junk mail folder. Confirm you entered your email correctly during registration. You can request a new code at resend_verification.php.
GCash payment proof upload fails | Ensure your receipt image is in JPG, JPEG, or PNG format and the file size does not exceed 5MB.
Reservation status remains "Pending" | Payments are reviewed by cemetery administration during business hours. Please allow 1 to 4 hours for review, or contact the office with your GCash reference number.
Grave not appearing in cemetery map search | Ensure the deceased's name is spelled correctly. If the interment occurred recently, verify with the administrator that the deceased record has been saved.
Transfer of remains request rejected | Verify that the required 8-to-10-year skeletal decomposition interval policy has been met and destination plot is available.
Map tiles or plot markers not loading | Check your internet connection and refresh the browser. Ensure JavaScript is enabled in your browser settings.
Forgot account password | Click "Forgot Password?" on the login page, enter your registered email address, and follow the password reset link sent to your email.

24. SECURITY AND PRIVACY
________________________________________
All users of the Garden of Remembrance Memorial Park CMIS must observe the following privacy and security guidelines:
• Keep your account credentials strictly confidential. Never share your password or email verification codes.
• Client information and payment receipts are stored securely and accessible solely by authorized cemetery personnel for verification purposes.
• Administrators must not share administrative credentials or disclose client financial records without authorization.
• Always click the Logout button when finishing your session, particularly when accessing the system on shared or public computers.

25. LOGGING OUT
________________________________________
To exit the system securely:
Step 1: Click the Logout link located in the top navigation bar or mobile menu drawer.
Step 2: The system will terminate your active session and redirect you safely to the Public Home Page or Login screen.
Step 3: Close your web browser tab.

Do not simply close your browser window without logging out first, especially on shared or public devices. Always click Logout to safeguard your personal account and plot records.

Screenshot: Logout / Session Ended Confirmation
