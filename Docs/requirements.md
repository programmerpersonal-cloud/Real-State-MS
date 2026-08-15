SAXANE REAL ESTATE MANAGEMENT SYSTEM
Comprehensive Functional and Non-Functional Requirements Specification
1. SYSTEM OVERVIEW

The Saxane Real Estate Management System is a web-based platform designed to manage every major real estate operation for a modern property company. The system must support property listing, customer management, rental and sales transactions, lease and contract handling, maintenance tracking, owner management, reporting, document storage, notifications, and administrative control.

The platform must replace manual paper-based workflows with a centralized, secure, searchable, and scalable digital system.

The system must support the following core business domains:

Property management
Customer management
Owner management
Tenant and lease management
Rent and sales transaction management
Property history tracking
Maintenance and repair tracking
Reservation and booking management
Financial and commission management
Documents and contract management
Notifications and communication
Reports, analytics, and audit logs
Multi-branch business operation
Role-based access control
2. USER ROLES AND PERMISSIONS
2.1 Administrator

The administrator has full access to the system and is responsible for overall supervision.

Responsibilities
Manage users, roles, and permissions
Approve or reject property listings
View and manage all properties, customers, owners, leases, payments, maintenance requests, and reports
Access audit logs and system settings
Configure company-wide settings
Manage branches
Monitor overall business performance
2.2 Real Estate Agent

Agents handle day-to-day property operations.

Responsibilities
Add and edit property listings
Upload property photos, videos, and documents
Manage inquiries and appointments
Register customer interactions
Manage rented and sold properties assigned to them
Track commissions and activity history
Update property availability and status
2.3 Customer / Client

Customers are property seekers, buyers, or tenants.

Responsibilities
Register and manage profile
Search and filter properties
Save favorite listings
Book property visits
Send inquiries
Request reservations
View rental history and active rentals
Receive notifications and reminders
View payments, contracts, and receipts if applicable
2.4 Property Owner

A property owner is the legal owner of one or more properties managed by the company.

Responsibilities
View owned properties
View rental or sales status
View payments and income reports
View lease and contract status
Receive notifications about their properties
Upload or manage ownership documents if allowed
2.5 Maintenance Staff / Technician

Maintenance staff handle reported property issues.

Responsibilities
View assigned maintenance requests
Update repair status
Add notes, costs, and completion details
Upload proof of completed work
Communicate with admin or agents
3. MASTER FUNCTIONAL REQUIREMENTS
3.1 AUTHENTICATION AND ACCOUNT MANAGEMENT MODULE
3.1.1 User Registration

The system shall allow users to create accounts securely.

Required registration fields
Full name
Email address
Phone number
Username
Password
Confirm password
Role
Branch association if needed
Functional requirements
The system shall validate all required fields before account creation.
The system shall verify that email and username are unique.
The system shall encrypt passwords before storage.
The system shall prevent registration with invalid or incomplete data.
The system shall support profile image upload.
The system shall optionally require email verification after registration.
3.1.2 User Login

The system shall allow registered users to log in based on their credentials.

Functional requirements
The system shall authenticate users using email/username and password.
The system shall redirect users to role-based dashboards after login.
The system shall lock accounts after repeated failed login attempts.
The system shall store login timestamps for audit purposes.
The system shall support session timeout and logout.
3.1.3 Password Recovery

The system shall provide secure password recovery.

Functional requirements
Users shall request password reset using email or phone.
The system shall send a reset link or verification code.
Reset tokens shall expire after a defined time.
Users shall create a new password after verification.
3.2 ROLE-BASED ACCESS CONTROL MODULE

The system shall enforce role-based permissions so that each user can only access allowed features.

Core rules
Clients cannot edit property prices.
Agents can manage only their assigned or permitted listings.
Owners can view only their own properties and reports.
Maintenance staff can access only maintenance-related tasks.
Admins can access all modules.
Permission categories
Create
View
Update
Delete
Approve
Assign
Export
Download
Print
3.3 CUSTOMER MANAGEMENT MODULE

This module is one of the most important missing areas and must be fully implemented.

3.3.1 Customer Profile

The system shall store complete customer profiles.

Required customer fields
Customer ID
Full name
Email
Phone number
Address
Gender if needed
National ID number
Profile photo
Emergency contact
Employment status
Occupation
Guarantor name
Guarantor contact
Customer type: tenant, buyer, both
Functional requirements
The system shall allow staff to register customers manually.
Customers shall also be able to self-register online.
The system shall support customer search and profile viewing.
The system shall store customer documents such as ID copies.
The system shall keep a complete record of each customer’s property history.
3.3.2 Customer Rental History

The system shall maintain a full history of all apartments or properties rented by a customer.

History details
Property rented
Rental start date
Rental end date
Monthly rent amount
Deposit amount
Payment status
Lease status
Move-in date
Move-out date
Reason for leaving if recorded
Outstanding balance
Blacklist status if applicable
Functional requirements
The system shall show all previous rentals linked to a customer.
The system shall show the current active rental if one exists.
The system shall allow filtering by date, property, and status.
The system shall support historical records even after lease termination.
The system shall preserve customer history for reporting and decision-making.
3.3.3 Customer Transaction History

The system shall record all customer-related transactions.

Transaction examples
Rent payments
Deposit payments
Property purchases
Refunds
Late fee charges
Commission-related payment references
3.3.4 Customer Notes and Risk Profile

The system shall support internal staff notes about customers.

Notes may include
Payment reliability
Complaint history
Behavior remarks
Risk level
Blacklist reason
Follow-up reminders
3.4 PROPERTY MANAGEMENT MODULE
3.4.1 Property Registration

The system shall allow agents or admins to register properties.

Required property fields
Property ID
Property title
Property type
Category: house, apartment, villa, land, office, commercial space, warehouse
Location
Branch
Size/area
Number of rooms
Number of bathrooms
Number of floors
Price
Rent amount
Deposit amount
Description
Availability status
Ownership status
Furnished status
Utilities included
Parking availability
Security features
Property code
Assigned owner
Assigned agent
Approval status
Functional requirements
The system shall allow multiple images per property.
The system shall allow property videos and virtual tour media if available.
The system shall allow marking a property as available, reserved, rented, sold, inactive, or under maintenance.
The system shall allow editing property details by authorized users only.
The system shall support property archiving rather than permanent deletion when needed.
3.4.2 Property Image and Media Management

The system shall support rich media for each property.

Functional requirements
Upload multiple images.
Upload short videos.
Support cover image selection.
Validate file type, size, and security.
Compress images for performance.
Preview images before publishing.
3.4.3 Property Availability Calendar

The system shall show when a property is available, reserved, rented, or blocked.

Functional requirements
Show occupied dates
Show reservation dates
Show maintenance-blocked periods
Show lease expiration dates
Prevent double booking
3.4.4 Property History Tracking

Every property shall have a complete activity history.

Property history shall include
Creation date
Modification date
Price changes
Status changes
Previous tenants
Previous owners if transferred
Maintenance records
Inspection history
Reservation history
Rental history
Sales history
Functional requirements
The system shall record who changed what and when.
The system shall allow administrators to view full property audit history.
The system shall preserve old versions of important records.
3.5 OWNER MANAGEMENT MODULE

This module is essential for properties managed on behalf of external owners.

Owner profile fields
Owner ID
Full name
Phone
Email
Address
National ID
Bank details if needed
Owned properties
Commission agreement
Revenue share agreement
Document attachments
Functional requirements
The system shall register and manage property owners.
The system shall link each property to one owner or multiple owners if needed.
The system shall show all properties owned by a specific owner.
The system shall show payment summaries and income generated for each owner.
The system shall support owner contracts and ownership documents.
3.6 LEASE AND CONTRACT MANAGEMENT MODULE

This is one of the most important professional features.

3.6.1 Lease Creation

The system shall create rental lease agreements between customer and owner/company.

Lease fields
Lease ID
Tenant ID
Property ID
Owner ID
Start date
End date
Rent amount
Deposit
Payment schedule
Late fee rules
Terms and conditions
Signature status
Contract file
Functional requirements
The system shall generate lease records for rental properties.
The system shall store scanned or digital lease agreements.
The system shall track contract expiration dates.
The system shall notify users before lease expiration.
The system shall support lease renewal and termination.
The system shall preserve historical lease documents.
3.6.2 Lease Renewal

The system shall allow renewal of active leases.

Functional requirements
Extend lease period
Update rent if applicable
Generate new contract version
Preserve previous contract history
3.6.3 Lease Termination

The system shall support early or normal lease termination.

Functional requirements
Record termination reason
Record move-out date
Update property status
Close associated payment schedule
Preserve the full lease history
3.7 RENTAL MANAGEMENT MODULE
Functional requirements
Register a property as rented
Assign tenant to property
Track move-in and move-out dates
Track monthly rent due dates
Track deposits
Track late payments
Track rent arrears
Generate rent receipts
Record rent history
Rental rules
A property cannot be rented to two active tenants at the same time.
A property marked as rented must not appear as available unless the lease is terminated.
The system shall calculate overdue amounts automatically.
The system shall allow payment by cash, bank transfer, or other supported methods.
3.8 SALES MANAGEMENT MODULE

If the company sells properties, the system shall support full sales processing.

Functional requirements
Register property sale
Capture buyer details
Record sale amount
Record commission
Store payment schedule if installment-based
Update property status to sold
Preserve sale history
3.9 PAYMENT AND FINANCIAL MANAGEMENT MODULE
3.9.1 Rent Payments

The system shall record rent payments monthly or according to lease terms.

Payment details
Payment ID
Tenant
Property
Amount
Payment date
Due date
Payment method
Received by
Receipt number
Balance remaining
Penalty if overdue
3.9.2 Sales Payments

The system shall record property sale payments.

Functional requirements
Record full or partial payment
Track installment status
Mark sale as completed when fully paid
Generate sale receipt
3.9.3 Deposit Management

The system shall track security deposits.

Functional requirements
Record deposit amount
Record deposit received date
Show deposit refund status
Deduct damages if needed
Generate deposit settlement records
3.9.4 Expense Tracking

The system shall track all business-related expenses.

Expense examples
Maintenance costs
Cleaning costs
Utility bills
Advertising costs
Commission costs
Transportation costs
Printing and office expenses
3.10 COMMISSION MANAGEMENT MODULE

The system shall manage commissions for agents and staff.

Functional requirements
Calculate commission based on rental or sales agreement
Store commission percentage
Show paid and unpaid commissions
Generate commission reports by agent, branch, and date range
Allow admin to approve commission payouts
3.11 PAYMENT SCHEDULE AND ARREARS MODULE

This module is very important for rental business control.

Functional requirements
Generate monthly payment schedule automatically
Show due dates
Mark overdue invoices
Calculate penalties
Show unpaid balances
Notify tenants before and after due date
Show arrears by customer, property, and branch
3.12 MAINTENANCE MANAGEMENT MODULE

The system shall manage maintenance complaints and repair work.

Maintenance request fields
Request ID
Property ID
Customer ID
Reported by
Issue type
Description
Priority level
Photos
Date reported
Assigned technician
Cost estimate
Status
Completion date
Functional requirements
Customers shall report maintenance issues.
Agents or admins shall assign technicians.
Technicians shall update issue status.
The system shall track maintenance cost per property.
The system shall preserve maintenance history.
Maintenance statuses
New
Under review
Assigned
In progress
Completed
Rejected
Cancelled
3.13 RESERVATION AND BOOKING MODULE

The system shall allow users to reserve a property before renting or buying.

Functional requirements
Reserve available property
Set reservation expiration
Record reservation deposit if applicable
Show reservation status
Prevent conflicting reservations
Cancel expired reservations automatically
3.14 INQUIRY AND COMMUNICATION MODULE

The system shall allow users to contact agents or admin.

Inquiry features
Send inquiry message
Attach files or screenshots if needed
Assign inquiry to agent
Track inquiry status
Mark inquiry as open, pending, replied, closed
Communication methods
Internal message box
Email notifications
Optional live chat in future
3.15 INTERNAL MESSAGING MODULE

The system shall provide internal communication between users.

Functional requirements
Agent to customer messages
Admin to agent messages
Customer inquiry replies
Conversation history
Message timestamps
Read/unread status
3.16 FAVORITES AND COMPARISON MODULE
Functional requirements
Save favorite properties
Remove favorites
Compare multiple properties
View saved properties dashboard
Comparison criteria
Price
Location
Size
Rooms
Status
Availability
Amenities
3.17 SEARCH AND FILTER MODULE

The system shall provide powerful property search and filtering.

Search criteria
Keyword
Property type
Category
Location
Price range
Rent range
Sale range
Availability status
Furnished/unfurnished
Bedrooms
Bathrooms
Parking
Security
Branch
Owner
Agent
Functional requirements
Search results must be fast.
Filters shall update results dynamically or on submission.
Users shall sort properties by price, newest, most popular, or availability.
3.18 DOCUMENT MANAGEMENT MODULE

The system shall store and manage important business documents.

Document types
Ownership certificates
Lease agreements
Rental contracts
ID copies
Receipts
Invoices
Inspection forms
Maintenance reports
Company letters
Functional requirements
Upload documents securely
Download documents
View documents
Search documents
Track expiration dates
Restrict access based on role
3.19 NOTIFICATION MODULE

The system shall send automatic notifications.

Notification types
New inquiry received
Property approved
Reservation created
Lease expiring soon
Payment due
Payment overdue
Maintenance request updated
Password reset
New message received
Report ready
Notification channels
In-app notifications
Email
SMS if added later
3.20 BLACKLIST AND RISK MANAGEMENT MODULE

The system shall support blacklisting of risky customers.

Functional requirements
Add customer to blacklist
Record blacklist reason
Record dates and notes
Restrict future lease actions if necessary
Allow admin-only access to blacklist controls
Blacklist reasons
Non-payment
Fraud
Damage to property
Contract violation
Repeated complaints
3.21 REPORTING AND ANALYTICS MODULE

The system shall generate detailed business reports.

Reports required
Total properties
Available properties
Rented properties
Sold properties
Occupancy rate
Monthly revenue
Rental arrears
Sales summary
Commission summary
Owner income summary
Customer rental history
Maintenance summary
Branch performance
Agent performance
Blacklisted customers
Audit log summary
Functional requirements
Reports shall be filterable by date, branch, agent, property type, and owner.
Reports shall be exportable to PDF, Excel, and printable format.
The dashboard shall show charts and summary cards.
3.22 AUDIT TRAIL MODULE

The system shall keep a full record of important system actions.

Audit data to store
User ID
Action performed
Old value
New value
Date and time
IP address
Device or browser if possible
Actions to log
Login/logout
Property create/update/delete
Payment records
User account changes
Lease changes
Ownership changes
Maintenance updates
Role changes
3.23 BRANCH MANAGEMENT MODULE

If the company has or will expand to multiple branches, the system must support branch-level control.

Functional requirements
Register branches
Assign users to branches
Assign properties to branches
View reports by branch
Transfer properties between branches
Compare branch performance
3.24 SYSTEM SETTINGS MODULE

The admin shall control core company settings.

Settings include
Company name
Logo
Address
Contact details
Currency
Tax rate
Commission rate
Default language
Notification templates
Due date settings
Late fee rules
Approval rules
3.25 DASHBOARD MODULES
3.25.1 Admin Dashboard
Total users
Total properties
Active rentals
Pending approvals
Today’s inquiries
Overdue payments
Recent activities
Revenue chart
Branch summary
3.25.2 Agent Dashboard
Assigned properties
Pending inquiries
New reservations
Active leases
Commission balance
Maintenance requests
Upcoming visits
3.25.3 Customer Dashboard
Saved properties
Active lease
Payment history
Notifications
Appointment requests
Inquiry history
3.25.4 Owner Dashboard
Owned properties
Rental income
Active leases
Maintenance status
Statements and reports
4. NON-FUNCTIONAL REQUIREMENTS
4.1 Performance Requirements
The system shall load key pages within 3 seconds under normal conditions.
The system shall support multiple concurrent users.
Search and filtering must remain responsive even with large datasets.
4.2 Security Requirements
Passwords shall be hashed securely.
All traffic shall use HTTPS.
SQL injection protection shall be implemented.
Cross-site scripting protection shall be implemented.
CSRF protection shall be implemented.
File uploads shall be validated.
Sessions shall expire after inactivity.
Sensitive documents shall have restricted access.
Audit logs shall prevent hidden changes.
4.3 Reliability Requirements
The system shall support automatic backup.
The system shall support restore from backup.
The system shall minimize data loss during failure.
The system shall keep historical records safe.
4.4 Usability Requirements
The system shall be mobile responsive.
The user interface shall be clean and easy to navigate.
Labels shall be clear and human-readable.
Forms shall guide users with validation messages.
Reports and dashboards shall be visually understandable.
4.5 Maintainability Requirements
The system shall use modular code structure.
The system shall be easy to update and extend.
The codebase shall separate presentation, logic, and data access.
Future features shall be addable without redesigning the whole system.
4.6 Scalability Requirements
The system shall support growth in users, properties, and transactions.
The system shall allow future integration with mobile apps, online payments, maps, and SMS services.
5. DATABASE ENTITY REQUIREMENTS

The system should include, at minimum, the following entities:

Users
Roles
Customers
Owners
Agents
Properties
Property Images
Property History
Rentals
Sales
Payments
Deposits
Leases
Reservations
Inquiries
Messages
Maintenance Requests
Commissions
Notifications
Documents
Branches
Audit Logs
Blacklist Records
Settings

Each entity must include:

Primary key
Foreign keys
Created at
Updated at
Status fields where relevant
6. INPUT VALIDATION RULES

The system shall validate the following:

Email must be valid and unique
Phone number must contain only acceptable characters
Password must meet minimum strength
Property price must be numeric and non-negative
Dates must follow logical order
Property images must be safe file types
Required fields cannot be blank
Payment amount must not exceed business rules
Lease dates must not overlap for the same property
7. BUSINESS RULES
A property cannot be rented to more than one active tenant at the same time.
A sold property cannot be marked as available unless manually restored by admin.
Lease expiration must trigger reminders before the end date.
A customer with blacklist status may be restricted from new rentals.
All major changes must be recorded in the audit log.
Property approval must be completed before public visibility.
Owner and agent financial records must remain traceable at all times.
Maintenance requests must remain linked to the property history.
8. FINAL IMPLEMENTATION GOAL

The final system must behave like a professional real estate enterprise platform with full support for:

customer history
rental history
property history
lease contracts
ownership records
payment tracking
maintenance operations
booking and reservations
document storage
notifications
analytics
branch management
audit trail
role-based access control

This makes the Saxane Real Estate Management System suitable not only for academic submission but also for real-world deployment.
