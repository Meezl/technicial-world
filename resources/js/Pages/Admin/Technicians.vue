<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="technicians" />

        <main class="main-content technicians-page">
            <section class="technicians-hero">
                <div class="hero-copy">
                    <span class="hero-kicker">Technician Management</span>
                    <h1>Build and manage your field team</h1>
                    <p>Review availability, specialization, workload, and technician profiles from one cleaner workspace.</p>
                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="fas fa-hard-hat"></i>
                            {{ technicians.length }} technicians
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-check-circle"></i>
                            {{ availableCount }} available
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-briefcase"></i>
                            {{ totalJobsCount }} total jobs tracked
                        </span>
                    </div>
                </div>

                <div class="hero-action-card">
                    <div class="hero-action-copy">
                        <span class="section-kicker">Team Snapshot</span>
                        <h3>Technician Operations</h3>
                        <p>{{ busyCount }} busy, {{ onLeaveCount }} on leave, and {{ uniqueSpecializations.length }} specialization{{ uniqueSpecializations.length === 1 ? '' : 's' }} covered.</p>
                    </div>

                    <div class="hero-action-grid">
                        <div class="hero-action-tile">
                            <span>Average Rating</span>
                            <strong>{{ averageRating.toFixed(1) }}/5</strong>
                        </div>
                        <div class="hero-action-tile">
                            <span>Top Specialization</span>
                            <strong>{{ topSpecialization }}</strong>
                        </div>
                    </div>

                    <button @click="showCreateModal" class="btn btn-primary hero-add-btn">
                        <i class="fas fa-user-plus"></i>
                        Add New Technician
                    </button>
                </div>
            </section>

            <section class="stats-grid">
                <article class="stat-card tone-blue">
                    <div class="stat-topline">
                        <span class="stat-tag">Coverage</span>
                        <span class="stat-icon"><i class="fas fa-users"></i></span>
                    </div>
                    <h4>Total Technicians</h4>
                    <p class="stat-value">{{ technicians.length }}</p>
                    <span class="stat-footnote">Active records in the technician directory.</span>
                </article>
                <article class="stat-card tone-green">
                    <div class="stat-topline">
                        <span class="stat-tag">Ready</span>
                        <span class="stat-icon"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <h4>Available</h4>
                    <p class="stat-value">{{ availableCount }}</p>
                    <span class="stat-footnote">Technicians currently ready for assignment.</span>
                </article>
                <article class="stat-card tone-amber">
                    <div class="stat-topline">
                        <span class="stat-tag">Occupied</span>
                        <span class="stat-icon"><i class="fas fa-briefcase"></i></span>
                    </div>
                    <h4>Busy</h4>
                    <p class="stat-value">{{ busyCount }}</p>
                    <span class="stat-footnote">Currently engaged on active jobs.</span>
                </article>
                <article class="stat-card tone-slate">
                    <div class="stat-topline">
                        <span class="stat-tag">Skills</span>
                        <span class="stat-icon"><i class="fas fa-layer-group"></i></span>
                    </div>
                    <h4>Specializations</h4>
                    <p class="stat-value">{{ uniqueSpecializations.length }}</p>
                    <span class="stat-footnote">Distinct skill areas available in the team.</span>
                </article>
            </section>

            <section class="overview-grid">
                <article class="panel-card spotlight-card">
                    <div class="spotlight-header">
                        <div>
                            <span class="section-kicker">Team Spotlight</span>
                            <h3>Keep your strongest technician visible</h3>
                            <p>Surface standout performance and workload context before assigning the next job.</p>
                        </div>
                    </div>
                    <div v-if="topRatedTechnician" class="spotlight-body">
                        <div class="spotlight-person">
                            <div class="spotlight-avatar">
                                {{ getInitials(topRatedTechnician.user.name) }}
                            </div>
                            <div class="spotlight-copy">
                                <div class="spotlight-name-row">
                                    <h4>{{ topRatedTechnician.user.name }}</h4>
                                    <span class="spotlight-badge">Top rated</span>
                                </div>
                                <p>{{ topRatedTechnician.specialization || 'General technician' }} in {{ topRatedTechnician.location || 'Unassigned location' }}</p>
                                <div class="spotlight-metrics">
                                    <div>
                                        <span>Rating</span>
                                        <strong>{{ Number(topRatedTechnician.rating || 0).toFixed(1) }}/5</strong>
                                    </div>
                                    <div>
                                        <span>Jobs handled</span>
                                        <strong>{{ topRatedTechnician.total_jobs || 0 }}</strong>
                                    </div>
                                    <div>
                                        <span>Status</span>
                                        <strong>{{ formatAvailability(topRatedTechnician.availability) }}</strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="spotlight-empty">
                        <i class="fas fa-user-clock"></i>
                        <p>No technician insights are available yet.</p>
                    </div>
                </article>

                <article class="panel-card planner-card">
                    <div class="planner-header">
                        <div>
                            <span class="section-kicker">Planning Notes</span>
                            <h3>What the roster looks like right now</h3>
                        </div>
                    </div>
                    <div class="planner-list">
                        <div class="planner-item">
                            <span>Location coverage</span>
                            <strong>{{ locationCount }} location{{ locationCount === 1 ? '' : 's' }}</strong>
                            <p>Spread across your recorded service areas.</p>
                        </div>
                        <div class="planner-item">
                            <span>Average workload</span>
                            <strong>{{ averageJobsPerTechnician.toFixed(1) }} jobs / tech</strong>
                            <p>Useful for balancing new assignments.</p>
                        </div>
                        <div class="planner-item">
                            <span>Skill coverage</span>
                            <strong>{{ uniqueSkillsCount }} tracked skills</strong>
                            <p>Based on skills currently listed on technician profiles.</p>
                        </div>
                    </div>
                    <div class="planner-actions">
                        <button @click="clearFilters" class="btn btn-secondary planner-btn" :disabled="!activeFilterChips.length">
                            Clear filters
                        </button>
                        <button @click="showCreateModal" class="btn btn-primary planner-btn">
                            Add technician
                        </button>
                    </div>
                </article>
            </section>

            <section class="panel-section">
                <div class="panel-card filter-shell">
                    <div class="filter-header">
                        <div>
                            <span class="section-kicker">Directory Filters</span>
                            <h3>Find the right technician faster</h3>
                            <p>Search by name, ID, or email and narrow the list by specialization or availability.</p>
                        </div>
                    </div>
                    <div class="filter-grid">
                        <label class="filter-field filter-field-wide">
                            <span>Search</span>
                            <input type="text" v-model="searchQuery" placeholder="Search by name, ID, or email..." class="filter-input">
                        </label>
                        <label class="filter-field">
                            <span>Specialization</span>
                            <select v-model="specializationFilter" class="filter-input">
                                <option value="">All Specializations</option>
                                <option v-for="spec in uniqueSpecializations" :key="spec" :value="spec">{{ spec }}</option>
                            </select>
                        </label>
                        <label class="filter-field">
                            <span>Availability</span>
                            <select v-model="availabilityFilter" class="filter-input">
                                <option value="">All Availability</option>
                                <option value="available">Available</option>
                                <option value="busy">Busy</option>
                                <option value="on_leave">On Leave</option>
                            </select>
                        </label>
                    </div>
                    <div v-if="activeFilterChips.length" class="filter-chip-row">
                        <span v-for="chip in activeFilterChips" :key="chip" class="filter-chip">{{ chip }}</span>
                        <button @click="clearFilters" class="clear-chip-btn">Clear filters</button>
                    </div>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card directory-shell">
                    <div class="directory-header">
                        <div>
                            <span class="section-kicker">Team Directory</span>
                            <h3>All Technicians</h3>
                            <p>{{ filteredTechnicians.length }} result{{ filteredTechnicians.length === 1 ? '' : 's' }} shown.</p>
                        </div>
                        <div class="directory-summary">
                            <div class="directory-pill">
                                <span>Available now</span>
                                <strong>{{ filteredAvailableCount }}</strong>
                            </div>
                            <div class="directory-pill muted">
                                <span>Needs attention</span>
                                <strong>{{ filteredAttentionCount }}</strong>
                            </div>
                        </div>
                    </div>

                    <div v-if="filteredTechnicians.length" class="technician-grid">
                        <article v-for="tech in filteredTechnicians" :key="tech.id" class="technician-card">
                            <div class="technician-card-top">
                                <div class="technician-identity">
                                    <div class="technician-avatar">{{ getInitials(tech.user.name) }}</div>
                                    <div>
                                        <h4>{{ tech.user.name }}</h4>
                                        <span class="technician-subtext">{{ tech.technician_id }}</span>
                                        <span class="technician-subtext">{{ tech.user.email }}</span>
                                    </div>
                                </div>
                                <span :class="['status-badge', getAvailabilityClass(tech.availability)]">
                                    {{ formatAvailability(tech.availability) }}
                                </span>
                            </div>

                            <!-- Document compliance indicator -->
                            <div class="doc-compliance-bar">
                                <span class="doc-compliance-label">
                                    <i class="fas fa-folder-open"></i> Documents
                                </span>
                                <span :class="['doc-compliance-badge', getDocComplianceClass(tech)]">
                                    {{ getDocComplianceLabel(tech) }}
                                </span>
                            </div>

                            <div class="technician-meta-grid">
                                <div class="meta-item">
                                    <span>Specialization</span>
                                    <strong>{{ tech.specialization || 'Not set' }}</strong>
                                </div>
                                <div class="meta-item">
                                    <span>Location</span>
                                    <strong>{{ tech.location || 'Not set' }}</strong>
                                </div>
                                <div class="meta-item">
                                    <span>Rating</span>
                                    <strong>{{ Number(tech.rating || 0).toFixed(1) }}/5</strong>
                                </div>
                                <div class="meta-item">
                                    <span>Total Jobs</span>
                                    <strong>{{ tech.total_jobs || 0 }}</strong>
                                </div>
                            </div>

                            <div class="technician-contact-row">
                                <span><i class="fas fa-phone-alt"></i> {{ tech.user.phone || 'No phone on file' }}</span>
                                <span><i class="fas fa-map-marker-alt"></i> {{ tech.location || 'Location not set' }}</span>
                            </div>

                            <div class="skills-block">
                                <span class="skills-label">Skills</span>
                                <div class="skills-list">
                                    <span v-for="skill in tech.skills?.slice(0, 4)" :key="skill" class="skill-tag">{{ skill }}</span>
                                    <span v-if="tech.skills?.length > 4" class="skill-tag more">+{{ tech.skills.length - 4 }} more</span>
                                    <span v-if="!tech.skills?.length" class="empty-skill-tag">No skills listed</span>
                                </div>
                            </div>

                            <p v-if="tech.bio" class="technician-bio">{{ truncate(tech.bio, 130) }}</p>
                            <p v-else class="technician-bio empty">No bio provided yet.</p>

                            <div class="technician-actions">
                                <button @click="viewTechnician(tech)" class="btn btn-secondary btn-sm">View</button>
                                <Link :href="`/admin/technicians/${tech.id}/report`" class="btn btn-info btn-sm">Report</Link>
                                <button @click="editTechnician(tech)" class="btn btn-secondary btn-sm">Edit</button>
                                <button @click="deleteTechnician(tech)" class="btn btn-danger-soft btn-sm">Delete</button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <p>No technicians found matching your criteria.</p>
                    </div>
                </div>
            </section>
        </main>

        <!-- ==================== CREATE / EDIT MODAL ==================== -->
        <div v-if="showModal" class="modal-overlay">
            <div class="modal-content modal-lg" @click.stop>
                <div class="modal-header">
                    <div>
                        <span class="section-kicker">Technician Form</span>
                        <h3>{{ isEditing ? 'Edit Technician' : 'Add New Technician' }}</h3>
                    </div>
                    <button @click="closeModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-banner">
                        <div>
                            <span class="form-banner-label">{{ isEditing ? 'Editing existing profile' : 'Creating a new profile' }}</span>
                            <p>{{ isEditing ? 'Update technician details and documents.' : 'Capture identity, work profile, skills, and mandatory documents.' }}</p>
                        </div>
                        <span class="form-banner-pill">{{ isEditing ? 'Update record' : 'New technician' }}</span>
                    </div>

                    <!-- Step indicator for create -->
                    <div v-if="!isEditing" class="form-steps">
                        <div :class="['step', { active: formStep === 1, done: formStep > 1 }]" @click="formStep = 1">
                            <span class="step-num">1</span>
                            <span class="step-label">Details</span>
                        </div>
                        <div class="step-line"></div>
                        <div :class="['step', { active: formStep === 2, done: formStep > 2 }]" @click="formStep >= 2 ? formStep = 2 : null">
                            <span class="step-num">2</span>
                            <span class="step-label">Documents</span>
                        </div>
                    </div>

                    <form @submit.prevent="saveTechnician" enctype="multipart/form-data">
                        <!-- STEP 1: Basic Details (always shown for edit, step 1 for create) -->
                        <div v-show="isEditing || formStep === 1">
                            <div class="form-section">
                                <h4>Basic Details</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Full Name <span class="req">*</span></label>
                                        <input type="text" v-model="form.name" required placeholder="Enter full name">
                                    </div>
                                    <div class="form-group">
                                        <label>Email <span class="req">*</span></label>
                                        <input type="email" v-model="form.email" required placeholder="Enter email address">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="tel" v-model="form.phone" placeholder="Enter phone number">
                                    </div>
                                    <div class="form-group">
                                        <label>Technician ID</label>
                                        <input type="text" v-model="form.technician_id" placeholder="Auto-generated if empty" :disabled="isEditing">
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h4>Work Profile</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Specialization <span class="req">*</span></label>
                                        <select v-model="form.specialization" required>
                                            <option value="">Select specialization</option>
                                            <option value="Electrical">Electrical</option>
                                            <option value="Plumbing">Plumbing</option>
                                            <option value="HVAC">HVAC</option>
                                            <option value="Carpentry">Carpentry</option>
                                            <option value="Painting">Painting</option>
                                            <option value="Roofing">Roofing</option>
                                            <option value="Flooring">Flooring</option>
                                            <option value="General Maintenance">General Maintenance</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Location <span class="req">*</span></label>
                                        <input type="text" v-model="form.location" required placeholder="Enter location/city">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Availability</label>
                                        <select v-model="form.availability">
                                            <option value="available">Available</option>
                                            <option value="busy">Busy</option>
                                            <option value="on_leave">On Leave</option>
                                        </select>
                                    </div>
                                    <div class="form-group" v-if="!isEditing">
                                        <label>Login Credentials</label>
                                        <div class="auto-credential-note">
                                            <i class="fas fa-envelope-circle-check"></i>
                                            <span>A secure temporary password will be auto-generated and emailed to the technician on save.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h4>Skills & Bio</h4>
                                <div class="form-group full-width">
                                    <label>Skills (comma-separated)</label>
                                    <input type="text" v-model="skillsInput" placeholder="e.g. Wiring, Panel Installation, Troubleshooting">
                                    <small class="form-hint">Enter skills separated by commas.</small>
                                </div>
                                <div class="form-group full-width">
                                    <label>Bio</label>
                                    <textarea v-model="form.bio" rows="4" placeholder="Brief description about the technician's experience and expertise"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 2: Mandatory Documents (create only) -->
                        <div v-show="!isEditing && formStep === 2">
                            <div class="form-section">
                                <h4><i class="fas fa-file-upload"></i> Mandatory Documents</h4>
                                <p class="form-section-desc">All documents below are required for technician registration. Accepted formats: PDF, JPG, PNG (max 5MB).</p>

                                <div class="doc-upload-grid">
                                    <div class="doc-upload-item">
                                        <label class="doc-upload-label">
                                            <i class="fas fa-id-badge"></i>
                                            NCA License <span class="req">*</span>
                                        </label>
                                        <div :class="['file-drop-zone', { 'has-file': docFiles.nca_license }]" @click="$refs.ncaInput.click()">
                                            <input ref="ncaInput" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => handleFileSelect(e, 'nca_license')" hidden>
                                            <template v-if="docFiles.nca_license">
                                                <i class="fas fa-check-circle file-ok-icon"></i>
                                                <span class="file-name">{{ docFiles.nca_license.name }}</span>
                                                <button type="button" class="file-remove" @click.stop="removeFile('nca_license')"><i class="fas fa-times"></i></button>
                                            </template>
                                            <template v-else>
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Click to upload NCA License</span>
                                            </template>
                                        </div>
                                        <span v-if="formErrors.doc_nca_license" class="field-error">{{ formErrors.doc_nca_license }}</span>
                                    </div>

                                    <div class="doc-upload-item">
                                        <label class="doc-upload-label">
                                            <i class="fas fa-graduation-cap"></i>
                                            Tertiary Education Certificate <span class="req">*</span>
                                        </label>
                                        <div :class="['file-drop-zone', { 'has-file': docFiles.tertiary_cert }]" @click="$refs.tertInput.click()">
                                            <input ref="tertInput" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => handleFileSelect(e, 'tertiary_cert')" hidden>
                                            <template v-if="docFiles.tertiary_cert">
                                                <i class="fas fa-check-circle file-ok-icon"></i>
                                                <span class="file-name">{{ docFiles.tertiary_cert.name }}</span>
                                                <button type="button" class="file-remove" @click.stop="removeFile('tertiary_cert')"><i class="fas fa-times"></i></button>
                                            </template>
                                            <template v-else>
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Click to upload Tertiary Certificate</span>
                                            </template>
                                        </div>
                                        <span v-if="formErrors.doc_tertiary_cert" class="field-error">{{ formErrors.doc_tertiary_cert }}</span>
                                    </div>

                                    <div class="doc-upload-item">
                                        <label class="doc-upload-label">
                                            <i class="fas fa-id-card"></i>
                                            ID Card <span class="req">*</span>
                                        </label>
                                        <div :class="['file-drop-zone', { 'has-file': docFiles.id_card }]" @click="$refs.idInput.click()">
                                            <input ref="idInput" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => handleFileSelect(e, 'id_card')" hidden>
                                            <template v-if="docFiles.id_card">
                                                <i class="fas fa-check-circle file-ok-icon"></i>
                                                <span class="file-name">{{ docFiles.id_card.name }}</span>
                                                <button type="button" class="file-remove" @click.stop="removeFile('id_card')"><i class="fas fa-times"></i></button>
                                            </template>
                                            <template v-else>
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Click to upload ID Card</span>
                                            </template>
                                        </div>
                                        <span v-if="formErrors.doc_id_card" class="field-error">{{ formErrors.doc_id_card }}</span>
                                    </div>

                                    <div class="doc-upload-item">
                                        <label class="doc-upload-label">
                                            <i class="fas fa-camera"></i>
                                            Passport Size Photo <span class="req">*</span>
                                        </label>
                                        <div :class="['file-drop-zone', { 'has-file': docFiles.passport_photo }]" @click="$refs.photoInput.click()">
                                            <input ref="photoInput" type="file" accept=".jpg,.jpeg,.png" @change="e => handleFileSelect(e, 'passport_photo')" hidden>
                                            <template v-if="docFiles.passport_photo">
                                                <i class="fas fa-check-circle file-ok-icon"></i>
                                                <span class="file-name">{{ docFiles.passport_photo.name }}</span>
                                                <button type="button" class="file-remove" @click.stop="removeFile('passport_photo')"><i class="fas fa-times"></i></button>
                                            </template>
                                            <template v-else>
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Click to upload Passport Photo</span>
                                            </template>
                                        </div>
                                        <span v-if="formErrors.doc_passport_photo" class="field-error">{{ formErrors.doc_passport_photo }}</span>
                                    </div>

                                    <div class="doc-upload-item">
                                        <label class="doc-upload-label">
                                            <i class="fas fa-file-invoice"></i>
                                            KRA PIN Certificate <span class="req">*</span>
                                        </label>
                                        <div :class="['file-drop-zone', { 'has-file': docFiles.kra_pin }]" @click="$refs.kraInput.click()">
                                            <input ref="kraInput" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => handleFileSelect(e, 'kra_pin')" hidden>
                                            <template v-if="docFiles.kra_pin">
                                                <i class="fas fa-check-circle file-ok-icon"></i>
                                                <span class="file-name">{{ docFiles.kra_pin.name }}</span>
                                                <button type="button" class="file-remove" @click.stop="removeFile('kra_pin')"><i class="fas fa-times"></i></button>
                                            </template>
                                            <template v-else>
                                                <i class="fas fa-cloud-upload-alt"></i>
                                                <span>Click to upload KRA PIN Certificate</span>
                                            </template>
                                        </div>
                                        <span v-if="formErrors.doc_kra_pin" class="field-error">{{ formErrors.doc_kra_pin }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents section for EDIT mode -->
                        <div v-if="isEditing && editingTechnicianDocs.length" class="form-section">
                            <h4><i class="fas fa-folder-open"></i> Uploaded Documents</h4>
                            <div class="existing-docs-list">
                                <div v-for="doc in editingTechnicianDocs" :key="doc.id" class="existing-doc-row">
                                    <div class="existing-doc-info">
                                        <i :class="getDocIcon(doc.document_type)"></i>
                                        <div>
                                            <span class="existing-doc-type">{{ formatDocType(doc.document_type) }}</span>
                                            <span class="existing-doc-name">{{ doc.file_name }}</span>
                                        </div>
                                    </div>
                                    <span :class="['doc-status-badge', doc.verified ? 'verified' : 'pending']">
                                        {{ doc.verified ? 'Verified' : 'Pending' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Upload additional doc for edit mode -->
                        <div v-if="isEditing" class="form-section">
                            <h4><i class="fas fa-upload"></i> Upload / Replace Document</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Document Type</label>
                                    <select v-model="editDocType">
                                        <option value="">Select document type</option>
                                        <option value="nca_license">NCA License</option>
                                        <option value="tertiary_cert">Tertiary Certificate</option>
                                        <option value="id_card">ID Card</option>
                                        <option value="passport_photo">Passport Photo</option>
                                        <option value="pin_cert">KRA PIN Certificate</option>
                                        <option value="technical_cert">Technical Certificate</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>File</label>
                                    <input ref="editDocInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="file-input-styled">
                                </div>
                            </div>
                            <button type="button" @click="uploadEditDoc" class="btn btn-secondary btn-sm" :disabled="!editDocType || uploadingDoc">
                                <i class="fas fa-upload"></i> {{ uploadingDoc ? 'Uploading...' : 'Upload Document' }}
                            </button>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="closeModal" class="btn btn-secondary">Cancel</button>
                    <button v-if="!isEditing && formStep === 1" @click="goToStep2" class="btn btn-primary">
                        Next: Documents <i class="fas fa-arrow-right"></i>
                    </button>
                    <button v-if="!isEditing && formStep === 2" @click="formStep = 1" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button v-if="isEditing || formStep === 2" @click="saveTechnician" class="btn btn-primary" :disabled="saving">
                        {{ saving ? 'Saving...' : (isEditing ? 'Update Technician' : 'Create Technician') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ==================== VIEW / DOCUMENTS MODAL ==================== -->
        <div v-if="showViewModal" class="modal-overlay">
            <div class="modal-content modal-lg profile-modal" @click.stop>
                <div class="modal-header">
                    <div>
                        <span class="section-kicker">Technician Profile</span>
                        <h3>{{ viewingTechnician?.user?.name }}</h3>
                    </div>
                    <button @click="closeViewModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div v-if="viewingTechnician" class="technician-profile">
                        <div class="profile-hero">
                            <div class="profile-avatar">{{ getInitials(viewingTechnician.user.name) }}</div>
                            <div class="profile-hero-copy">
                                <div class="profile-title-row">
                                    <h2>{{ viewingTechnician.user.name }}</h2>
                                    <span class="technician-id">{{ viewingTechnician.technician_id }}</span>
                                </div>
                                <p>{{ viewingTechnician.specialization }} &bull; {{ viewingTechnician.location }}</p>
                                <span :class="['status-badge', getAvailabilityClass(viewingTechnician.availability)]">
                                    {{ formatAvailability(viewingTechnician.availability) }}
                                </span>
                            </div>
                        </div>

                        <div class="profile-grid">
                            <div class="profile-stat">
                                <span>Email</span>
                                <strong>{{ viewingTechnician.user.email }}</strong>
                            </div>
                            <div class="profile-stat">
                                <span>Phone</span>
                                <strong>{{ viewingTechnician.user.phone || 'Not provided' }}</strong>
                            </div>
                            <div class="profile-stat">
                                <span>Rating</span>
                                <strong>{{ Number(viewingTechnician.rating || 0).toFixed(1) }}/5</strong>
                            </div>
                            <div class="profile-stat">
                                <span>Total Jobs</span>
                                <strong>{{ viewingTechnician.total_jobs || 0 }}</strong>
                            </div>
                        </div>

                        <div class="profile-note">
                            <i class="fas fa-clipboard-list"></i>
                            <p>{{ getWorkloadMessage(viewingTechnician) }}</p>
                        </div>

                        <div v-if="viewingTechnician.skills?.length" class="profile-section">
                            <h4>Skills</h4>
                            <div class="skills-list">
                                <span v-for="skill in viewingTechnician.skills" :key="skill" class="skill-tag">{{ skill }}</span>
                            </div>
                        </div>

                        <div class="profile-section">
                            <h4>Bio</h4>
                            <p>{{ viewingTechnician.bio || 'No bio provided yet.' }}</p>
                        </div>

                        <!-- Documents Section with Approval -->
                        <div class="profile-section">
                            <div class="docs-section-header">
                                <h4><i class="fas fa-folder-open"></i> Documents</h4>
                                <span class="doc-count-badge">{{ viewingTechnician.documents?.length || 0 }} uploaded</span>
                            </div>

                            <div v-if="viewingTechnician.documents?.length" class="docs-review-list">
                                <div v-for="doc in viewingTechnician.documents" :key="doc.id" class="doc-review-card">
                                    <div class="doc-review-top">
                                        <div class="doc-review-info">
                                            <i :class="getDocIcon(doc.document_type)" class="doc-type-icon"></i>
                                            <div>
                                                <span class="doc-review-type">{{ formatDocType(doc.document_type) }}</span>
                                                <span class="doc-review-name">{{ doc.file_name }}</span>
                                                <span class="doc-review-date" v-if="doc.created_at">Uploaded {{ formatDate(doc.created_at) }}</span>
                                            </div>
                                        </div>
                                        <span :class="['doc-status-badge', doc.verified ? 'verified' : 'pending']">
                                            <i :class="doc.verified ? 'fas fa-check-circle' : 'fas fa-clock'"></i>
                                            {{ doc.verified ? 'Verified' : 'Pending Review' }}
                                        </span>
                                    </div>
                                    <div class="doc-review-actions">
                                        <a :href="`/admin/technician-documents/${doc.id}/download`" target="_blank" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <a :href="`/admin/technician-documents/${doc.id}/download`" download class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                        <button
                                            v-if="!doc.verified"
                                            @click="verifyDocument(doc.id, 'approve')"
                                            class="btn btn-primary btn-sm"
                                            :disabled="verifyingDoc === doc.id"
                                        >
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button
                                            v-if="doc.verified"
                                            @click="verifyDocument(doc.id, 'reject')"
                                            class="btn btn-danger-soft btn-sm"
                                            :disabled="verifyingDoc === doc.id"
                                        >
                                            <i class="fas fa-times"></i> Revoke
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-else class="empty-docs-state">
                                <i class="fas fa-file-excel"></i>
                                <p>No documents uploaded yet.</p>
                            </div>

                            <!-- Missing mandatory docs warning -->
                            <div v-if="getMissingDocs(viewingTechnician).length" class="missing-docs-alert">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>Missing mandatory documents:</strong>
                                    <span v-for="(doc, i) in getMissingDocs(viewingTechnician)" :key="doc">
                                        {{ doc }}{{ i < getMissingDocs(viewingTechnician).length - 1 ? ', ' : '' }}
                                    </span>
                                </div>
                            </div>

                            <!-- Upload for this technician -->
                            <div class="doc-upload-inline">
                                <h5>Upload Document</h5>
                                <div class="form-row">
                                    <div class="form-group">
                                        <select v-model="viewDocType" class="filter-input">
                                            <option value="">Select type</option>
                                            <option value="nca_license">NCA License</option>
                                            <option value="tertiary_cert">Tertiary Certificate</option>
                                            <option value="id_card">ID Card</option>
                                            <option value="passport_photo">Passport Photo</option>
                                            <option value="pin_cert">KRA PIN Certificate</option>
                                            <option value="technical_cert">Technical Certificate</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <input ref="viewDocInput" type="file" accept=".pdf,.jpg,.jpeg,.png" class="file-input-styled">
                                    </div>
                                </div>
                                <button type="button" @click="uploadViewDoc" class="btn btn-primary btn-sm" :disabled="!viewDocType || uploadingDoc">
                                    <i class="fas fa-upload"></i> {{ uploadingDoc ? 'Uploading...' : 'Upload' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="closeViewModal" class="btn btn-secondary">Close</button>
                    <Link :href="`/admin/technicians/${viewingTechnician?.id}/report`" class="btn btn-info">
                        <i class="fas fa-chart-bar"></i> View Report
                    </Link>
                    <button @click="editTechnician(viewingTechnician)" class="btn btn-primary">Edit Technician</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'

const props = defineProps({
    technicians: { type: Array, default: () => [] },
    documentTypes: { type: Object, default: () => ({}) },
})

const searchQuery = ref('')
const specializationFilter = ref('')
const availabilityFilter = ref('')
const showModal = ref(false)
const showViewModal = ref(false)
const isEditing = ref(false)
const viewingTechnician = ref(null)
const skillsInput = ref('')
const formStep = ref(1)
const saving = ref(false)
const formErrors = ref({})
const uploadingDoc = ref(false)
const verifyingDoc = ref(null)
const editDocType = ref('')
const viewDocType = ref('')

const mandatoryDocTypes = ['nca_license', 'tertiary_cert', 'id_card', 'passport_photo', 'pin_cert']

const form = ref({
    name: '', email: '', phone: '', technician_id: '',
    specialization: '', location: '', availability: 'available',
    password: '', bio: '', skills: [],
})

const docFiles = ref({
    nca_license: null,
    tertiary_cert: null,
    id_card: null,
    passport_photo: null,
    kra_pin: null,
})

const editingTechnicianDocs = computed(() => {
    if (!isEditing.value || !form.value.id) return []
    const tech = props.technicians.find(t => t.id === form.value.id)
    return tech?.documents || []
})

// ==================== COMPUTED ====================
const uniqueSpecializations = computed(() => {
    const specs = props.technicians.map(t => t.specialization).filter(Boolean)
    return [...new Set(specs)].sort()
})

const filteredTechnicians = computed(() => {
    let filtered = props.technicians
    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase()
        filtered = filtered.filter(t =>
            t.user.name.toLowerCase().includes(q) ||
            t.technician_id.toLowerCase().includes(q) ||
            t.user.email.toLowerCase().includes(q)
        )
    }
    if (specializationFilter.value) filtered = filtered.filter(t => t.specialization === specializationFilter.value)
    if (availabilityFilter.value) filtered = filtered.filter(t => t.availability === availabilityFilter.value)

    return [...filtered].sort((a, b) => {
        const order = { available: 0, busy: 1, on_leave: 2 }
        const diff = (order[a.availability] ?? 9) - (order[b.availability] ?? 9)
        return diff !== 0 ? diff : (b.rating || 0) - (a.rating || 0)
    })
})

const availableCount = computed(() => props.technicians.filter(t => t.availability === 'available').length)
const busyCount = computed(() => props.technicians.filter(t => t.availability === 'busy').length)
const onLeaveCount = computed(() => props.technicians.filter(t => t.availability === 'on_leave').length)
const totalJobsCount = computed(() => props.technicians.reduce((s, t) => s + Number(t.total_jobs || 0), 0))
const locationCount = computed(() => new Set(props.technicians.map(t => t.location).filter(Boolean)).size)
const uniqueSkillsCount = computed(() => new Set(props.technicians.flatMap(t => t.skills || []).filter(Boolean)).size)
const averageJobsPerTechnician = computed(() => props.technicians.length ? totalJobsCount.value / props.technicians.length : 0)
const averageRating = computed(() => {
    if (!props.technicians.length) return 0
    return props.technicians.reduce((s, t) => s + Number(t.rating || 0), 0) / props.technicians.length
})
const topSpecialization = computed(() => {
    if (!uniqueSpecializations.value.length) return 'N/A'
    const counts = props.technicians.reduce((m, t) => {
        if (t.specialization) m[t.specialization] = (m[t.specialization] || 0) + 1
        return m
    }, {})
    return Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0] || 'N/A'
})
const topRatedTechnician = computed(() => {
    if (!props.technicians.length) return null
    return [...props.technicians].sort((a, b) => {
        const d = Number(b.rating || 0) - Number(a.rating || 0)
        return d !== 0 ? d : Number(b.total_jobs || 0) - Number(a.total_jobs || 0)
    })[0]
})
const activeFilterChips = computed(() => {
    const chips = []
    if (searchQuery.value) chips.push(`Search: ${searchQuery.value}`)
    if (specializationFilter.value) chips.push(`Specialization: ${specializationFilter.value}`)
    if (availabilityFilter.value) chips.push(`Availability: ${formatAvailability(availabilityFilter.value)}`)
    return chips
})
const filteredAvailableCount = computed(() => filteredTechnicians.value.filter(t => t.availability === 'available').length)
const filteredAttentionCount = computed(() => filteredTechnicians.value.filter(t => t.availability !== 'available').length)

watch(skillsInput, (v) => { form.value.skills = v ? v.split(',').map(s => s.trim()).filter(Boolean) : [] })

// ==================== HELPERS ====================
const formatAvailability = (a) => ({ available: 'Available', busy: 'Busy', on_leave: 'On Leave' }[a] || a)
const getAvailabilityClass = (a) => ({ available: 'approved', busy: 'review', on_leave: 'rejected' }[a] || 'pending')
const getInitials = (name) => name ? name.split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2) : 'T'
const truncate = (text, limit = 120) => text && text.length > limit ? `${text.slice(0, limit)}...` : (text || '')

const formatDocType = (type) => {
    const map = {
        nca_license: 'NCA License',
        tertiary_cert: 'Tertiary Certificate',
        id_card: 'ID Card',
        passport_photo: 'Passport Photo',
        pin_cert: 'KRA PIN Certificate',
        technical_cert: 'Technical Certificate',
        vetting_form: 'Vetting Form',
        other: 'Other Document',
    }
    return map[type] || type?.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) || 'Document'
}

const getDocIcon = (type) => {
    const map = {
        nca_license: 'fas fa-id-badge',
        tertiary_cert: 'fas fa-graduation-cap',
        id_card: 'fas fa-id-card',
        passport_photo: 'fas fa-camera',
        pin_cert: 'fas fa-file-invoice',
        technical_cert: 'fas fa-certificate',
        vetting_form: 'fas fa-clipboard-check',
        other: 'fas fa-file-alt',
    }
    return map[type] || 'fas fa-file-alt'
}

const getDocComplianceClass = (tech) => {
    const docs = tech.documents || []
    const uploaded = mandatoryDocTypes.filter(t => docs.some(d => d.document_type === t || (t === 'pin_cert' && d.document_type === 'pin_cert')))
    if (uploaded.length === mandatoryDocTypes.length) {
        const allVerified = mandatoryDocTypes.every(t => docs.find(d => d.document_type === t)?.verified)
        return allVerified ? 'compliance-complete' : 'compliance-partial'
    }
    return uploaded.length > 0 ? 'compliance-partial' : 'compliance-missing'
}

const getDocComplianceLabel = (tech) => {
    const docs = tech.documents || []
    const uploaded = mandatoryDocTypes.filter(t => docs.some(d => d.document_type === t))
    if (uploaded.length === mandatoryDocTypes.length) {
        const allVerified = mandatoryDocTypes.every(t => docs.find(d => d.document_type === t)?.verified)
        return allVerified ? 'All Verified' : `${uploaded.length}/${mandatoryDocTypes.length} Uploaded`
    }
    return `${uploaded.length}/${mandatoryDocTypes.length} Docs`
}

const getMissingDocs = (tech) => {
    const docs = tech?.documents || []
    const missing = []
    const labelMap = { nca_license: 'NCA License', tertiary_cert: 'Tertiary Certificate', id_card: 'ID Card', passport_photo: 'Passport Photo', pin_cert: 'KRA PIN Certificate' }
    mandatoryDocTypes.forEach(t => {
        if (!docs.some(d => d.document_type === t)) missing.push(labelMap[t])
    })
    return missing
}

const formatDate = (dateString) => {
    if (!dateString) return ''
    return new Date(dateString).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const getWorkloadMessage = (tech) => {
    if (!tech) return 'No technician selected.'
    if (tech.availability === 'available' && (tech.total_jobs || 0) < 5) return 'This technician looks ready for additional assignments and has lighter recorded workload.'
    if (tech.availability === 'busy') return 'This technician is currently marked busy, so confirm capacity before assigning another job.'
    if (tech.availability === 'on_leave') return 'This technician is on leave, so keep them out of the short-term assignment pool.'
    return 'This technician has an established job history and should be reviewed alongside current workload.'
}

// ==================== FILE HANDLING ====================
const handleFileSelect = (event, key) => {
    const file = event.target.files[0]
    if (file) {
        docFiles.value[key] = file
        // Clear error for this field
        const errorKey = key === 'kra_pin' ? 'doc_kra_pin' : `doc_${key}`
        if (formErrors.value[errorKey]) delete formErrors.value[errorKey]
    }
}

const removeFile = (key) => {
    docFiles.value[key] = null
}

// ==================== ACTIONS ====================
const clearFilters = () => { searchQuery.value = ''; specializationFilter.value = ''; availabilityFilter.value = '' }

const showCreateModal = () => {
    isEditing.value = false
    formStep.value = 1
    formErrors.value = {}
    resetForm()
    showModal.value = true
}

const editTechnician = (tech) => {
    isEditing.value = true
    formStep.value = 1
    formErrors.value = {}
    form.value = {
        id: tech.id, name: tech.user.name, email: tech.user.email,
        phone: tech.user.phone || '', technician_id: tech.technician_id,
        specialization: tech.specialization, location: tech.location,
        availability: tech.availability, bio: tech.bio || '',
        password: '', skills: tech.skills || [],
    }
    skillsInput.value = tech.skills?.join(', ') || ''
    showViewModal.value = false
    showModal.value = true
}

const viewTechnician = (tech) => {
    viewingTechnician.value = tech
    showViewModal.value = true
}

const deleteTechnician = (tech) => {
    if (confirm(`Are you sure you want to delete ${tech.user.name}? This action cannot be undone.`)) {
        router.delete(`/admin/technicians/${tech.id}`)
    }
}

const goToStep2 = () => {
    // Validate step 1 fields (password is auto-generated on save, no longer required here)
    if (!form.value.name || !form.value.email || !form.value.specialization || !form.value.location) {
        alert('Please fill in all required fields before proceeding.')
        return
    }
    formStep.value = 2
}

const saveTechnician = () => {
    if (saving.value) return

    if (isEditing.value) {
        // Edit mode - simple put
        saving.value = true
        router.put(`/admin/technicians/${form.value.id}`, form.value, {
            onSuccess: () => { closeModal(); saving.value = false },
            onError: () => { saving.value = false },
        })
    } else {
        // Create mode - use FormData for file uploads
        formErrors.value = {}

        // Validate all mandatory docs are present
        const missingDocs = []
        if (!docFiles.value.nca_license) missingDocs.push('doc_nca_license')
        if (!docFiles.value.tertiary_cert) missingDocs.push('doc_tertiary_cert')
        if (!docFiles.value.id_card) missingDocs.push('doc_id_card')
        if (!docFiles.value.passport_photo) missingDocs.push('doc_passport_photo')
        if (!docFiles.value.kra_pin) missingDocs.push('doc_kra_pin')

        if (missingDocs.length) {
            missingDocs.forEach(k => { formErrors.value[k] = 'This document is required.' })
            formStep.value = 2
            return
        }

        saving.value = true
        const formData = new FormData()
        Object.entries(form.value).forEach(([key, val]) => {
            if (key === 'skills' && Array.isArray(val)) {
                val.forEach((s, i) => formData.append(`skills[${i}]`, s))
            } else if (val !== null && val !== undefined && val !== '') {
                formData.append(key, val)
            }
        })

        formData.append('doc_nca_license', docFiles.value.nca_license)
        formData.append('doc_tertiary_cert', docFiles.value.tertiary_cert)
        formData.append('doc_id_card', docFiles.value.id_card)
        formData.append('doc_passport_photo', docFiles.value.passport_photo)
        formData.append('doc_kra_pin', docFiles.value.kra_pin)

        router.post('/admin/technicians', formData, {
            forceFormData: true,
            onSuccess: () => { closeModal(); saving.value = false },
            onError: (errors) => {
                formErrors.value = errors
                saving.value = false
                // If doc errors, switch to step 2
                if (Object.keys(errors).some(k => k.startsWith('doc_'))) formStep.value = 2
            },
        })
    }
}

const uploadEditDoc = () => {
    const input = document.querySelector('[ref="editDocInput"]') || document.querySelectorAll('input[type="file"]')[5]
    // Use the ref
    const fileInput = document.querySelector('.modal-content.modal-lg input[type="file"].file-input-styled')
    if (!fileInput?.files?.[0] || !editDocType.value) return

    uploadingDoc.value = true
    const fd = new FormData()
    fd.append('document', fileInput.files[0])
    fd.append('document_type', editDocType.value)

    router.post(`/admin/technicians/${form.value.id}/documents`, fd, {
        forceFormData: true,
        onSuccess: () => { uploadingDoc.value = false; editDocType.value = ''; if (fileInput) fileInput.value = '' },
        onError: () => { uploadingDoc.value = false },
    })
}

const uploadViewDoc = () => {
    const fileInputs = document.querySelectorAll('.profile-modal input[type="file"]')
    const fileInput = fileInputs[fileInputs.length - 1]
    if (!fileInput?.files?.[0] || !viewDocType.value || !viewingTechnician.value) return

    uploadingDoc.value = true
    const fd = new FormData()
    fd.append('document', fileInput.files[0])
    fd.append('document_type', viewDocType.value)

    router.post(`/admin/technicians/${viewingTechnician.value.id}/documents`, fd, {
        forceFormData: true,
        preserveState: true,
        onSuccess: () => {
            uploadingDoc.value = false
            viewDocType.value = ''
            if (fileInput) fileInput.value = ''
            // Refresh the viewing technician data
            const updated = props.technicians.find(t => t.id === viewingTechnician.value.id)
            if (updated) viewingTechnician.value = updated
        },
        onError: () => { uploadingDoc.value = false },
    })
}

const verifyDocument = (docId, action) => {
    verifyingDoc.value = docId
    router.post(`/admin/technician-documents/${docId}/verify`, { action }, {
        preserveState: true,
        onSuccess: () => {
            verifyingDoc.value = null
            // Refresh viewing technician
            const updated = props.technicians.find(t => t.id === viewingTechnician.value?.id)
            if (updated) viewingTechnician.value = updated
        },
        onError: () => { verifyingDoc.value = null },
    })
}

const resetForm = () => {
    form.value = { name: '', email: '', phone: '', technician_id: '', specialization: '', location: '', availability: 'available', password: '', bio: '', skills: [] }
    skillsInput.value = ''
    docFiles.value = { nca_license: null, tertiary_cert: null, id_card: null, passport_photo: null, kra_pin: null }
    editDocType.value = ''
}

const closeModal = () => { showModal.value = false; resetForm() }
const closeViewModal = () => { showViewModal.value = false; viewingTechnician.value = null }

defineOptions({ layout: null })
</script>

<style>
@import url('../../../css/dashboard-app.css');

.technicians-page {
    background: radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem), linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

/* Auto-credential note shown in place of the manual password field */
.auto-credential-note {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    padding: 0.65rem 0.8rem;
    background: #ECFDF5;
    border: 1px solid #A7F3D0;
    border-left: 3px solid #16A34A;
    border-radius: 8px;
    font-size: 0.82rem;
    color: #065F46;
    line-height: 1.4;
}
.auto-credential-note i { color: #16A34A; margin-top: 2px; }

.technicians-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.75rem;
}

.hero-copy, .hero-action-card, .stat-card, .filter-shell, .directory-shell, .technician-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.hero-copy {
    padding: 2rem;
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)), #00334b;
    color: #f8fafc;
}

.hero-kicker, .section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.hero-kicker { color: rgba(226, 232, 240, 0.82); }
.hero-copy h1 { margin: 0.85rem 0 0; color: #ffffff; font-size: clamp(2rem, 3vw, 2.5rem); }
.hero-copy p { margin: 0.9rem 0 0; max-width: 38rem; color: rgba(226, 232, 240, 0.88); line-height: 1.6; }

.hero-pills { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; }
.hero-pill { display: inline-flex; align-items: center; gap: 0.55rem; padding: 0.75rem 1rem; border-radius: 999px; background: rgba(255, 255, 255, 0.12); color: #f8fafc; font-size: 0.88rem; font-weight: 600; }
.hero-pill.muted { background: rgba(15, 23, 42, 0.22); }

.hero-action-card, .filter-shell, .directory-shell { padding: 1.4rem; border-radius: 24px; background: rgba(255, 255, 255, 0.92); }
.section-kicker { color: #0f6c8f; }
.hero-action-copy h3, .filter-header h3, .directory-header h3 { margin: 0.35rem 0 0; color: #0f172a; }
.hero-action-copy p, .filter-header p, .directory-header p { margin: 0.45rem 0 0; color: #64748b; line-height: 1.55; }

.hero-action-grid, .stats-grid, .technician-meta-grid, .profile-grid, .filter-grid { display: grid; gap: 1rem; }
.hero-action-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 1rem 0 1.2rem; }

.hero-action-tile, .meta-item, .profile-stat { padding: 1rem; border-radius: 18px; background: #f8fafc; border: 1px solid #e2e8f0; }
.hero-action-tile span, .meta-item span, .profile-stat span, .skills-label, .form-hint { color: #64748b; font-size: 0.8rem; }
.hero-action-tile strong, .meta-item strong, .profile-stat strong { display: block; margin-top: 0.35rem; color: #0f172a; font-size: 1.08rem; }
.hero-add-btn { width: 100%; justify-content: center; border-radius: 999px; }

.stats-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 1.5rem; }
.overview-grid { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(300px, 0.9fr); gap: 1rem; margin-bottom: 1.5rem; }

.stat-card { padding: 1.35rem; border-radius: 22px; background: rgba(255, 255, 255, 0.92); }
.tone-blue { background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), #ffffff); }
.tone-green { background: linear-gradient(180deg, rgba(240, 253, 244, 0.96), #ffffff); }
.tone-amber { background: linear-gradient(180deg, rgba(255, 251, 235, 0.98), #ffffff); }
.tone-slate { background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), #ffffff); }

.stat-topline { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.95rem; }
.stat-tag { display: inline-flex; padding: 0.35rem 0.65rem; border-radius: 999px; background: rgba(255, 255, 255, 0.78); color: #475569; font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
.stat-icon { width: 2.8rem; height: 2.8rem; border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #ffffff; background: linear-gradient(135deg, #0f6c8f, #38bdf8); }
.tone-green .stat-icon { background: linear-gradient(135deg, #0f766e, #22c55e); }
.tone-amber .stat-icon { background: linear-gradient(135deg, #c2410c, #f59e0b); }
.tone-slate .stat-icon { background: linear-gradient(135deg, #475569, #94a3b8); }
.stat-card h4 { margin: 0; color: #475569; font-size: 0.92rem; }
.stat-value { margin: 0.45rem 0 0; font-size: 1.65rem; font-weight: 800; color: #0f172a; }
.stat-footnote { display: block; margin-top: 0.6rem; color: #64748b; font-size: 0.84rem; line-height: 1.45; }

.filter-grid { grid-template-columns: 2fr 1fr 1fr; margin-top: 1rem; }

.spotlight-card, .planner-card { padding: 1.4rem; border-radius: 24px; background: rgba(255, 255, 255, 0.92); }
.spotlight-header h3, .planner-header h3 { margin: 0.35rem 0 0; color: #0f172a; }
.spotlight-header p { margin: 0.45rem 0 0; color: #64748b; line-height: 1.55; }
.spotlight-body, .planner-list { margin-top: 1rem; }

.spotlight-person { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 1rem; padding: 1rem; border-radius: 20px; background: linear-gradient(180deg, #f8fbfd 0%, #ffffff 100%); border: 1px solid #e2e8f0; }
.spotlight-avatar { width: 4.25rem; height: 4.25rem; border-radius: 22px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0f6c8f, #38bdf8); color: #ffffff; font-weight: 800; font-size: 1.15rem; }
.spotlight-name-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.65rem; }
.spotlight-name-row h4 { margin: 0; color: #0f172a; }
.spotlight-badge { display: inline-flex; align-items: center; padding: 0.35rem 0.7rem; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
.spotlight-copy p { margin: 0.4rem 0 0; color: #64748b; }
.spotlight-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.8rem; margin-top: 1rem; }
.spotlight-metrics span, .planner-item span, .directory-pill span, .form-banner-label { color: #64748b; font-size: 0.78rem; }
.spotlight-metrics strong, .planner-item strong, .directory-pill strong { display: block; margin-top: 0.32rem; color: #0f172a; }
.spotlight-empty { display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem; padding: 1rem; border-radius: 18px; background: #f8fafc; color: #64748b; }

.planner-list { display: grid; gap: 0.85rem; }
.planner-item { padding: 1rem; border-radius: 18px; background: #f8fafc; border: 1px solid #e2e8f0; }
.planner-item p { margin: 0.35rem 0 0; color: #64748b; line-height: 1.5; }
.planner-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1rem; }
.planner-btn { flex: 1 1 180px; justify-content: center; }

.filter-field { display: flex; flex-direction: column; gap: 0.45rem; font-size: 0.88rem; font-weight: 600; color: #334155; }
.filter-input, .technician-form input, .technician-form select, .technician-form textarea { width: 100%; padding: 0.82rem 0.9rem; border-radius: 14px; border: 1px solid #d7dee7; background: #f8fafc; color: #0f172a; transition: border-color 0.2s ease, box-shadow 0.2s ease; box-sizing: border-box; }
.filter-input:focus, .technician-form input:focus, .technician-form select:focus, .technician-form textarea:focus { outline: none; border-color: rgba(14, 116, 144, 0.45); box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12); background: #ffffff; }

.filter-chip-row { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1rem; }
.filter-chip { display: inline-flex; align-items: center; padding: 0.5rem 0.8rem; border-radius: 999px; background: #e0f2fe; color: #0f6c8f; font-size: 0.82rem; font-weight: 700; }
.clear-chip-btn { border: none; background: transparent; color: #0f6c8f; font-size: 0.82rem; font-weight: 700; cursor: pointer; }

.directory-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.2rem; }
.directory-summary { display: flex; flex-wrap: wrap; gap: 0.75rem; }
.directory-pill { min-width: 8.5rem; padding: 0.85rem 1rem; border-radius: 18px; background: #eff6ff; border: 1px solid #bfdbfe; }
.directory-pill.muted { background: #fff7ed; border-color: #fed7aa; }

.technician-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; }
.technician-card { padding: 1.15rem; border-radius: 22px; background: #ffffff; }
.technician-card-top, .technician-identity, .profile-hero, .profile-title-row, .technician-actions { display: flex; gap: 0.85rem; }
.technician-card-top { justify-content: space-between; align-items: flex-start; }

.technician-avatar, .profile-avatar { width: 3.2rem; height: 3.2rem; border-radius: 18px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0f6c8f, #38bdf8); color: #ffffff; font-weight: 800; flex-shrink: 0; }
.technician-identity h4, .profile-title-row h2 { margin: 0; color: #0f172a; }
.technician-subtext { display: block; margin-top: 0.2rem; color: #64748b; font-size: 0.82rem; }

.technician-meta-grid, .profile-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 1rem 0; }
.technician-contact-row { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.9rem; color: #64748b; font-size: 0.84rem; }
.technician-contact-row span { display: inline-flex; align-items: center; gap: 0.45rem; }

/* Document compliance bar on cards */
.doc-compliance-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; margin: 0.75rem 0; border-radius: 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
.doc-compliance-label { font-size: 0.8rem; color: #64748b; font-weight: 600; display: flex; align-items: center; gap: 0.4rem; }
.doc-compliance-badge { padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.72rem; font-weight: 700; }
.compliance-complete { background: #dcfce7; color: #166534; }
.compliance-partial { background: #fef3c7; color: #92400e; }
.compliance-missing { background: #fee2e2; color: #991b1b; }

.skills-block { margin-bottom: 0.9rem; }
.skills-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.45rem; }
.skill-tag, .empty-skill-tag, .technician-id, .status-badge { display: inline-flex; align-items: center; justify-content: center; padding: 0.38rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; white-space: nowrap; }
.skill-tag { background: #eef2ff; color: #334155; }
.skill-tag.more { background: #0f6c8f; color: #ffffff; }
.empty-skill-tag { background: #f8fafc; color: #94a3b8; border: 1px dashed #cbd5e1; }
.technician-bio { margin: 0; color: #475569; line-height: 1.55; min-height: 3rem; }
.technician-bio.empty { color: #94a3b8; font-style: italic; }
.technician-actions { flex-wrap: wrap; margin-top: 1rem; }
.btn-danger-soft { background: #fee2e2; color: #991b1b; }
.btn-danger-soft:hover { background: #fecaca; }

.status-badge.approved { background: #dcfce7; color: #166534; }
.status-badge.review { background: #fef3c7; color: #92400e; }
.status-badge.rejected { background: #fee2e2; color: #991b1b; }

/* Modal sizing */
.modal-lg { max-width: 860px; }
.profile-modal { max-width: 860px; }

/* Form steps */
.form-steps { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 1.5rem; padding: 1rem; background: #f8fafc; border-radius: 14px; }
.step { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 999px; cursor: pointer; transition: all 0.2s; }
.step-num { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.82rem; background: #e2e8f0; color: #64748b; transition: all 0.2s; }
.step-label { font-weight: 600; font-size: 0.88rem; color: #64748b; }
.step.active .step-num { background: #0f6c8f; color: white; }
.step.active .step-label { color: #0f172a; }
.step.done .step-num { background: #16a34a; color: white; }
.step.done .step-label { color: #16a34a; }
.step-line { width: 40px; height: 2px; background: #e2e8f0; margin: 0 0.5rem; }

/* Form sections */
.form-section { padding-bottom: 1.2rem; border-bottom: 1px solid #edf2f7; }
.form-section:last-child { padding-bottom: 0; border-bottom: none; }
.form-section h4 { margin: 0 0 0.9rem; color: #0f172a; display: flex; align-items: center; gap: 0.5rem; }
.form-section-desc { margin: -0.5rem 0 1rem; color: #64748b; font-size: 0.88rem; line-height: 1.5; }
.req { color: #dc2626; }

.form-banner { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.2rem; padding: 1rem; border-radius: 18px; background: linear-gradient(180deg, #f8fbfd 0%, #ffffff 100%); border: 1px solid #dbeafe; }
.form-banner p { margin: 0.35rem 0 0; color: #64748b; line-height: 1.55; }
.form-banner-pill { display: inline-flex; align-items: center; padding: 0.45rem 0.8rem; border-radius: 999px; background: #e0f2fe; color: #0f6c8f; font-size: 0.78rem; font-weight: 700; white-space: nowrap; }
.form-hint { display: block; margin-top: 0.4rem; }

.technician-form, form { display: flex; flex-direction: column; gap: 1.4rem; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
.form-group { display: flex; flex-direction: column; gap: 0.35rem; }
.form-group label { font-weight: 600; font-size: 0.88rem; color: #334155; }
.form-group.full-width { grid-column: 1 / -1; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 0.82rem 0.9rem; border-radius: 14px; border: 1px solid #d7dee7; background: #f8fafc; color: #0f172a; transition: border-color 0.2s ease, box-shadow 0.2s ease; box-sizing: border-box; font-family: inherit; font-size: 0.9rem; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: rgba(14, 116, 144, 0.45); box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12); background: #ffffff; }

/* Document upload grid */
.doc-upload-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.doc-upload-item { display: flex; flex-direction: column; gap: 0.5rem; }
.doc-upload-label { font-weight: 600; font-size: 0.85rem; color: #334155; display: flex; align-items: center; gap: 0.45rem; }
.doc-upload-label i { color: #0f6c8f; }

.file-drop-zone { display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 1rem; border-radius: 14px; border: 2px dashed #cbd5e1; background: #fafbfc; cursor: pointer; transition: all 0.2s; min-height: 60px; text-align: center; flex-wrap: wrap; }
.file-drop-zone:hover { border-color: #0f6c8f; background: #f0f9ff; }
.file-drop-zone i { font-size: 1.2rem; color: #94a3b8; }
.file-drop-zone span { font-size: 0.82rem; color: #64748b; }
.file-drop-zone.has-file { border-color: #16a34a; border-style: solid; background: #f0fdf4; }
.file-drop-zone.has-file i { color: #16a34a; }
.file-ok-icon { font-size: 1.2rem; }
.file-name { font-size: 0.82rem; color: #166534; font-weight: 600; word-break: break-all; }
.file-remove { border: none; background: #fee2e2; color: #dc2626; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.7rem; flex-shrink: 0; }
.field-error { font-size: 0.78rem; color: #dc2626; font-weight: 600; }

/* Existing docs in edit mode */
.existing-docs-list { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
.existing-doc-row { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; }
.existing-doc-info { display: flex; align-items: center; gap: 0.65rem; }
.existing-doc-info i { color: #0f6c8f; font-size: 1.1rem; }
.existing-doc-type { display: block; font-weight: 600; font-size: 0.85rem; color: #0f172a; }
.existing-doc-name { display: block; font-size: 0.75rem; color: #64748b; }

.doc-status-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.3rem 0.65rem; border-radius: 999px; font-size: 0.76rem; font-weight: 700; }
.doc-status-badge.verified { background: #dcfce7; color: #166534; }
.doc-status-badge.pending { background: #fef3c7; color: #92400e; }

.file-input-styled { padding: 0.6rem !important; }

/* Documents in view modal */
.docs-section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.docs-section-header h4 { margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.doc-count-badge { padding: 0.3rem 0.7rem; border-radius: 999px; background: #e0f2fe; color: #0f6c8f; font-size: 0.78rem; font-weight: 700; }

.docs-review-list { display: flex; flex-direction: column; gap: 0.75rem; }
.doc-review-card { padding: 1rem; border-radius: 14px; background: #f8fafc; border: 1px solid #e2e8f0; }
.doc-review-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 0.75rem; }
.doc-review-info { display: flex; align-items: flex-start; gap: 0.75rem; flex: 1; }
.doc-type-icon { font-size: 1.3rem; color: #0f6c8f; margin-top: 0.15rem; }
.doc-review-type { display: block; font-weight: 700; font-size: 0.9rem; color: #0f172a; }
.doc-review-name { display: block; font-size: 0.8rem; color: #64748b; margin-top: 0.15rem; }
.doc-review-date { display: block; font-size: 0.75rem; color: #94a3b8; margin-top: 0.15rem; }
.doc-review-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }

.empty-docs-state { text-align: center; padding: 2rem 1rem; color: #94a3b8; }
.empty-docs-state i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }

.missing-docs-alert { display: flex; gap: 0.75rem; padding: 1rem; border-radius: 12px; background: #fef2f2; border: 1px solid #fecaca; margin-top: 1rem; color: #991b1b; font-size: 0.88rem; }
.missing-docs-alert i { color: #dc2626; margin-top: 0.15rem; flex-shrink: 0; }
.missing-docs-alert strong { display: block; margin-bottom: 0.25rem; }

.doc-upload-inline { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; }
.doc-upload-inline h5 { margin: 0 0 0.75rem; font-size: 0.9rem; color: #0f172a; }

.technician-profile { display: flex; flex-direction: column; gap: 1.2rem; }
.profile-hero { align-items: center; padding-bottom: 1rem; border-bottom: 1px solid #e2e8f0; }
.technician-id { background: #eef2ff; color: #334155; }
.profile-hero-copy p, .profile-section p { margin: 0.45rem 0 0; color: #64748b; line-height: 1.6; }
.profile-note { display: flex; gap: 0.75rem; padding: 1rem; border-radius: 18px; background: #f8fafc; border: 1px solid #e2e8f0; color: #475569; }
.profile-note i { color: #0f6c8f; margin-top: 0.15rem; }
.profile-note p { margin: 0; line-height: 1.6; }
.profile-section { padding-top: 1rem; border-top: 1px solid #e2e8f0; }
.profile-section h4 { margin: 0 0 0.9rem; color: #0f172a; }

.empty-state { text-align: center; padding: 3rem 1rem; color: #64748b; }
.empty-state i { display: block; margin-bottom: 1rem; font-size: 2.5rem; color: #94a3b8; }

@media (max-width: 1180px) {
    .technicians-hero, .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .overview-grid { grid-template-columns: 1fr; }
    .filter-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 860px) {
    .technicians-hero, .stats-grid, .overview-grid, .hero-action-grid, .filter-grid, .technician-meta-grid, .profile-grid, .spotlight-metrics, .doc-upload-grid { grid-template-columns: 1fr; }
    .technician-card-top, .profile-hero, .profile-title-row, .directory-header, .form-banner, .form-row { flex-direction: column; align-items: flex-start; }
    .form-row { grid-template-columns: 1fr; }
    .spotlight-person { grid-template-columns: 1fr; }
    .technician-grid { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
    .stats-grid { grid-template-columns: 1fr; }
    .modal-content { width: 95%; max-height: 95vh; }
}
</style>
