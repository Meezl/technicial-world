# Requisition Management Module - Documentation

## Overview
The Requisition Management module provides comprehensive material requisition tracking with granular state management at the material line item level, strict state machine transitions, and role-based dashboards for different user types.

---

## Key Features

### 1. Granular State Management
- **Container Model**: `Requisition` acts as a container for multiple material items
- **Item-Level Tracking**: Each `RequisitionItem` has its own independent status and lifecycle
- **Parent Status**: Requisition status automatically updates based on child item statuses

### 2. Strict State Machine Flow
Material items transition through a strictly enforced flow:

```
Requested → Approved → Procured → Awaiting Payment → Paid → In Transit → Delivered → Acknowledged → Closed
     ↓          ↓           ↓              ↓
  Rejected   Rejected   Rejected       Rejected
```

**State Validation**:
- Each transition is validated using the `canTransitionTo()` method in `RequisitionItem` model
- Invalid transitions are blocked with HTTP 400 errors
- State machine constants defined in `RequisitionItem::VALID_TRANSITIONS`

### 3. Role-Based Access Control (RBAC)
Permissions are strictly enforced at the controller level:

| Role | Allowed Actions |
|------|----------------|
| **Site Foreman** | Create requisitions, Acknowledge deliveries |
| **Office Correspondent** | Approve, Reject, Update quantity |
| **Procurement Team** | Procure (add supplier/quote), Start transit, Mark delivered |
| **Accounts Team** | Approve payments |
| **Admin** | All actions (full oversight) |

---

## Database Schema

### Requisitions Table
```sql
- id
- project_id (foreign key)
- created_by (foreign key to users)
- status (pending, active, closed)
- description
- timestamps
```

### Requisition Items Table
```sql
- id
- requisition_id (foreign key)
- name
- quantity
- unit

# Status & State Machine
- status (requested, approved, procured, awaiting_payment, paid, in_transit, delivered, acknowledged, closed, rejected)

# Procurement Details
- supplier_name
- price
- currency
- quotation_file_path
- quotation_notes

# Dispatch Tracking
- tracking_number
- expected_delivery_date
- actual_delivery_date

# Acknowledgment Tracking
- acknowledged_at
- acknowledged_by (foreign key to users)
- delivery_condition_notes

# Approval Tracking
- approved_by (foreign key to users)
- approved_at
- rejection_reason

- timestamps
```

---

## Role-Based Dashboard Views

### 1. Site Foreman Dashboard (Mobile-First)
**File**: `resources/js/Pages/Admin/Requisitions/Dashboards/ForemanDashboard.vue`

**Features**:
- Simple interface to create new requisitions
- Add multiple material line items with quantity/units
- High-priority acknowledgment list for delivered items
- Document delivery condition/notes upon receipt
- View recent requisition history

**Key Actions**:
- Create Requisition: Add project, description, and multiple items
- Acknowledge Delivery: Confirm receipt with condition notes

---

### 2. Office Correspondent Dashboard
**File**: `resources/js/Pages/Admin/Requisitions/Dashboards/OfficeDashboard.vue`

**Features**:
- Review queue showing all requested items
- Inline quantity editing before approval
- Approve or reject specific material lines
- View requestor and project information

**Key Actions**:
- Approve Item: Transition from 'requested' to 'approved'
- Reject Item: Provide rejection reason
- Update Quantity: Modify requested quantities before approval

---

### 3. Procurement Team Dashboard
**File**: `resources/js/Pages/Admin/Requisitions/Dashboards/ProcurementDashboard.vue`

**Features**:
- **Procurement Queue**: Shows approved items needing quotes
- **Dispatch Queue**: Shows paid items ready for shipment
- Upload supplier quotations (PDF, images)
- Add supplier details and pricing
- Track shipments with tracking numbers
- Set expected delivery dates

**Key Actions**:
- Process Order: Add supplier, price, quotation file, notes
- Start Transit: Add tracking number and expected delivery date
- Mark Delivered: Update status when item arrives at site

---

### 4. Accounts Team Dashboard
**File**: `resources/js/Pages/Admin/Requisitions/Dashboards/AccountsDashboard.vue`

**Features**:
- Payment approval queue
- View supplier and amount details
- One-click payment approval

**Key Actions**:
- Approve Payment: Transition item from 'awaiting_payment' to 'paid'

---

### 5. Admin Dashboard
**File**: `resources/js/Pages/Admin/Requisitions/Dashboards/AdminDashboard.vue`

**Features**:
- **Overview Statistics**: Pending, In Procurement, In Transit, Completed counts
- **Advanced Filtering**: By project, status
- **Expandable View**: Click to see all items within a requisition
- **Complete Tracking**: View supplier, tracking, rejection reasons
- **Full Visibility**: Monitor all requisitions across all projects

---

## API Endpoints

### Routes (web.php)
```php
// List/View Requisitions (role-based dashboard)
GET /admin/requisitions -> RequisitionController@index

// Create New Requisition
POST /admin/requisitions -> RequisitionController@store

// Update Material Item Status
POST /admin/requisitions/items/{item} -> RequisitionController@updateItem

// Acknowledge Delivery (Site Foreman)
POST /admin/requisitions/items/{item}/acknowledge -> RequisitionController@acknowledgeItem
```

### Controller Actions

#### `updateItem(Request $request, RequisitionItem $item)`
Handles all state transitions based on the `action` parameter:

**Actions**:
- `approve`: Office → Approved
- `reject`: Any state → Rejected (requires notes)
- `update_qty`: Update quantity (Office)
- `procure`: Approved → Procured → Awaiting Payment (Procurement)
- `pay`: Awaiting Payment → Paid (Accounts)
- `transit`: Paid → In Transit (Procurement)
- `deliver`: In Transit → Delivered (Procurement)

**Request Parameters** (varies by action):
```php
[
    'action' => 'procure',
    'supplier_name' => 'ABC Suppliers',
    'price' => 1500.00,
    'currency' => 'USD',
    'quotation_file' => UploadedFile,
    'quotation_notes' => 'Quotation valid for 30 days'
]
```

**RBAC Enforcement**:
- Each action is validated against user role permissions
- Unauthorized actions return HTTP 403

**State Validation**:
- Transitions validated using `RequisitionItem::canTransitionTo()`
- Invalid transitions return HTTP 400

---

## Closure Logic

### Material Item Closure
A material item closes when:
1. Foreman acknowledges delivery
2. Status transitions: `delivered` → `acknowledged` → `closed`

### Requisition Closure
A parent requisition closes when:
- ALL child material items are in terminal states (`closed` or `rejected`)
- Implemented in `RequisitionController::updateRequisitionStatus()`

---

## State Machine Implementation

### Model: RequisitionItem.php
```php
// Valid state transitions map
public const VALID_TRANSITIONS = [
    'requested' => ['approved', 'rejected'],
    'approved' => ['procured', 'rejected'],
    'procured' => ['awaiting_payment'],
    'awaiting_payment' => ['paid', 'rejected'],
    'paid' => ['in_transit'],
    'in_transit' => ['delivered'],
    'delivered' => ['acknowledged'],
    'acknowledged' => ['closed'],
    'rejected' => [], // Terminal
    'closed' => [], // Terminal
];

// Validation method
public function canTransitionTo($newStatus) {
    return in_array($newStatus, self::VALID_TRANSITIONS[$this->status] ?? []);
}
```

### Controller Validation
```php
protected function validateTransition(RequisitionItem $item, $newStatus) {
    if (!$item->canTransitionTo($newStatus)) {
        abort(400, "Cannot transition from '{$item->status}' to '{$newStatus}'.");
    }
}
```

---

## File Upload Handling

### Quotation Upload (Procurement)
- **Accepted Formats**: PDF, JPG, JPEG, PNG
- **Storage Location**: `storage/app/public/quotations/`
- **Database Field**: `quotation_file_path`
- **Form Encoding**: `multipart/form-data`

**Implementation**:
```vue
<input type="file" @change="handleFileUpload" accept=".pdf,.jpg,.jpeg,.png">
```

```javascript
const handleFileUpload = (event) => {
    procureForm.quotation_file = event.target.files[0];
};
```

---

## Usage Examples

### Example 1: Complete Flow for a Material Item

1. **Foreman** creates requisition for "50 kg Cement"
   - Status: `requested`

2. **Office** reviews and approves
   - Status: `approved`
   - `approved_by` and `approved_at` recorded

3. **Procurement** processes order
   - Adds supplier "BuildMart", price $500
   - Uploads quotation PDF
   - Status: `procured` → auto-transitions to `awaiting_payment`

4. **Accounts** approves payment
   - Status: `paid`

5. **Procurement** ships item
   - Adds tracking "DHL123456"
   - Sets expected delivery: 2025-12-20
   - Status: `in_transit`

6. **Procurement** marks as delivered on arrival
   - Status: `delivered`
   - `actual_delivery_date` recorded

7. **Foreman** acknowledges receipt
   - Adds notes: "All cement bags in good condition"
   - Status: `acknowledged` → auto-transitions to `closed`
   - `acknowledged_at` and `acknowledged_by` recorded

---

### Example 2: Admin Monitoring

**Admin Dashboard** shows:
- **Pending Approval**: 5 items
- **In Procurement**: 12 items (approved, procured, awaiting payment)
- **In Transit**: 3 items (paid, in transit, delivered)
- **Completed**: 45 items

**Filtering**:
- Filter by Project: "Building A Construction"
- Filter by Status: "in_transit"
- Results: 2 items currently in transit for Building A

---

## Testing the Module

### Test Scenario: Full Lifecycle

1. **Create Test Users**:
   ```php
   $foreman = User::create(['role' => 'foreman', ...]);
   $office = User::create(['role' => 'office', ...]);
   $procurement = User::create(['role' => 'procurement', ...]);
   $accounts = User::create(['role' => 'accounts', ...]);
   $admin = User::create(['role' => 'admin', ...]);
   ```

2. **Login as Foreman** → Create requisition with items

3. **Login as Office** → Approve items

4. **Login as Procurement** → Add supplier, upload quote

5. **Login as Accounts** → Approve payment

6. **Login as Procurement** → Mark as in transit, then delivered

7. **Login as Foreman** → Acknowledge delivery

8. **Login as Admin** → Verify all item states and closure

### Verify State Machine
Try invalid transitions (should fail):
- Attempt `paid` → `requested` (should return 400)
- Attempt `closed` → `approved` (should return 400)

### Verify RBAC
- Foreman tries to approve payment (should return 403)
- Accounts tries to start transit (should return 403)

---

## Files Modified/Created

### Database
- `database/migrations/2025_12_12_055530_create_requisitions_table.php`
- `database/migrations/2025_12_12_055531_create_requisition_items_table.php`
- `database/migrations/2025_12_12_061751_add_procurement_tracking_fields_to_requisition_items_table.php` ✨ NEW

### Models
- `app/Models/Requisition.php` (Enhanced)
- `app/Models/RequisitionItem.php` (Enhanced with state machine)

### Controllers
- `app/Http/Controllers/Admin/RequisitionController.php` (Enhanced with RBAC and strict state validation)

### Frontend (Vue Components)
- `resources/js/Pages/Admin/Requisitions/Index.vue` (Enhanced)
- `resources/js/Pages/Admin/Requisitions/Dashboards/ForemanDashboard.vue` (Enhanced)
- `resources/js/Pages/Admin/Requisitions/Dashboards/OfficeDashboard.vue` (Existing)
- `resources/js/Pages/Admin/Requisitions/Dashboards/ProcurementDashboard.vue` (Enhanced with file upload)
- `resources/js/Pages/Admin/Requisitions/Dashboards/AccountsDashboard.vue` (Existing)
- `resources/js/Pages/Admin/Requisitions/Dashboards/AdminDashboard.vue` ✨ NEW

### Routes
- `routes/web.php` (Requisition routes already exist)

---

## Key Implementation Highlights

### ✅ Granular State Management
- Each material line item tracks independently
- Parent requisition status derived from children

### ✅ Strict State Machine
- Enforced via `VALID_TRANSITIONS` constant
- `canTransitionTo()` validation before every state change
- Invalid transitions blocked with clear error messages

### ✅ Complete RBAC
- `authorizeAction()` method validates role permissions
- All actions protected at controller level
- Admin has full access for oversight

### ✅ Procurement Tracking
- Quotation file uploads
- Supplier and pricing management
- Dispatch tracking with numbers and dates

### ✅ Acknowledgment & Closure
- Site foreman documents delivery condition
- Items auto-close after acknowledgment
- Requisition auto-closes when all items terminal

### ✅ Admin Oversight
- Dedicated comprehensive dashboard
- Statistics and filtering
- Full visibility into all requisitions

---

## Next Steps

1. **Configure Storage**: Ensure `storage/app/public` is linked
   ```bash
   php artisan storage:link
   ```

2. **Set User Roles**: Update users table with correct roles
   ```sql
   UPDATE users SET role = 'foreman' WHERE email = 'foreman@example.com';
   ```

3. **Test Workflows**: Follow test scenarios above

4. **Production Deployment**:
   - Run migrations: `php artisan migrate`
   - Build assets: `npm run build`
   - Clear cache: `php artisan optimize:clear`

---

## Support

For questions or issues with the Requisition Management module, refer to:
- State machine logic: `app/Models/RequisitionItem.php:55-71`
- RBAC implementation: `app/Http/Controllers/Admin/RequisitionController.php:163-175`
- Admin dashboard: `resources/js/Pages/Admin/Requisitions/Dashboards/AdminDashboard.vue`

---

**Module Status**: ✅ Complete and Production-Ready
**Last Updated**: December 12, 2025
