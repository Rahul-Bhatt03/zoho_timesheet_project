<template>
  <v-app>
    <v-app-bar color="primary" dark>
      <v-toolbar-title>Timesheet Processor</v-toolbar-title>
    </v-app-bar>

    <v-main>
      <v-container>
        <v-row>
          <v-col cols="12">
            <v-card>
              <v-card-title class="text-h5">
                Upload Zoho Timesheet Report
              </v-card-title>
              <v-card-text>
                <v-file-input
                  v-model="selectedFile"
                  label="Select Zoho Excel File"
                  accept=".xls,.xlsx"
                  prepend-icon="mdi-file-excel"
                  :loading="timesheetStore.isUploading"
                  :disabled="timesheetStore.isUploading"
                  @change="handleFileChange"
                ></v-file-input>
              </v-card-text>
              <v-card-actions>
                <v-btn
                  color="primary"
                  :loading="timesheetStore.isUploading"
                  :disabled="!selectedFile || timesheetStore.isUploading"
                  @click="handleUpload"
                >
                  Upload & Process
                </v-btn>
                <v-btn
                  color="success"
                  :loading="timesheetStore.isDownloading"
                  :disabled="!timesheetStore.hasData || timesheetStore.isDownloading"
                  @click="handleDownload"
                >
                  Download Processed Excel
                </v-btn>
                <v-btn
                  color="error"
                  :loading="timesheetStore.isClearing"
                  :disabled="!timesheetStore.hasData || timesheetStore.isClearing"
                  @click="handleClearData"
                >
                  Clear Data
                </v-btn>
              </v-card-actions>
            </v-card>
          </v-col>
        </v-row>

        <!-- Success/Error Messages -->
        <v-row v-if="timesheetStore.currentMessage.text">
          <v-col cols="12">
            <v-alert
              :type="timesheetStore.currentMessage.type"
              dismissible
              @click:close="timesheetStore.clearMessage()"
            >
              {{ timesheetStore.currentMessage.text }}
            </v-alert>
          </v-col>
        </v-row>

        <!-- Summary Statistics -->
        <v-row v-if="Object.keys(timesheetStore.summaryStats).length > 0">
          <v-col cols="12">
            <v-card>
              <v-card-title class="text-h6">Summary Statistics</v-card-title>
              <v-card-text>
                <v-row>
                  <v-col cols="12" md="3">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h4 primary--text">{{ timesheetStore.summaryStats.totalEntries }}</div>
                        <div class="text-subtitle-1">Total Entries</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                  <v-col cols="12" md="3">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h4 success--text">{{ timesheetStore.summaryStats.averageLeadTime }}</div>
                        <div class="text-subtitle-1">Avg Lead Time</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                  <v-col cols="12" md="3">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h4 info--text">{{ timesheetStore.summaryStats.averageCycleTime }}</div>
                        <div class="text-subtitle-1">Avg Cycle Time</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                  <v-col cols="12" md="3">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h4 warning--text">{{ timesheetStore.summaryStats.totalWeeklyPoints }}</div>
                        <div class="text-subtitle-1">Total Weekly Points</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                </v-row>
                <v-row>
                  <v-col cols="12" md="4">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h5 primary--text">{{ timesheetStore.summaryStats.totalEstimatedPoints }}</div>
                        <div class="text-subtitle-2">Total Estimated Points</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                  <v-col cols="12" md="4">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h5 secondary--text">{{ timesheetStore.summaryStats.totalActualPoints }}</div>
                        <div class="text-subtitle-2">Total Actual Points</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                  <v-col cols="12" md="4">
                    <v-card outlined>
                      <v-card-text class="text-center">
                        <div class="text-h5 accent--text">{{ timesheetStore.summaryStats.averageStoryPointAccuracy }}</div>
                        <div class="text-subtitle-2">Avg Story Point Accuracy</div>
                      </v-card-text>
                    </v-card>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- Data Table -->
        <v-row v-if="timesheetStore.hasData">
          <v-col cols="12">
            <v-card>
              <v-card-title class="text-h6">
                Processed Timesheet Data
                <v-spacer></v-spacer>
                <v-text-field
                  v-model="search"
                  append-icon="mdi-magnify"
                  label="Search"
                  single-line
                  hide-details
                ></v-text-field>
              </v-card-title>
              <v-data-table
                :headers="headers"
                :items="timesheetStore.processedEntries"
                :search="search"
                :items-per-page="10"
                class="elevation-1"
                dense
                :loading="timesheetStore.loading.loadingData"
                loading-text="Loading timesheet data..."
              >
                <template v-slot:item.formattedLogDate="{ item }">
                  {{ item.formattedLogDate }}
                </template>
                <template v-slot:item.formattedRequestedDate="{ item }">
                  {{ item.formattedRequestedDate }}
                </template>
                <template v-slot:item.formattedExpectedStartDate="{ item }">
                  {{ item.formattedExpectedStartDate }}
                </template>
                <template v-slot:item.formattedExpectedReleaseDate="{ item }">
                  {{ item.formattedExpectedReleaseDate }}
                </template>
                <template v-slot:item.formattedActualStartDate="{ item }">
                  {{ item.formattedActualStartDate }}
                </template>
                <template v-slot:item.formattedActualReleaseDate="{ item }">
                  {{ item.formattedActualReleaseDate }}
                </template>
                <template v-slot:item.item_type="{ item }">
                  <v-chip
                    :color="getItemTypeColor(item.item_type)"
                    small
                    text-color="white"
                  >
                    {{ item.item_type }}
                  </v-chip>
                </template>
                <template v-slot:item.status="{ item }">
                  <v-chip
                    :color="getStatusColor(item.status)"
                    small
                    text-color="white"
                  >
                    {{ item.status }}
                  </v-chip>
                </template>
              </v-data-table>
            </v-card>
          </v-col>
        </v-row>

        <!-- Formula Management -->
        <v-row v-if="Object.keys(timesheetStore.formulas).length > 0">
          <v-col cols="12">
            <v-card>
              <v-card-title class="text-h6">
                Formula Configuration
                <v-spacer></v-spacer>
                <v-btn
                  color="info"
                  small
                  @click="refreshFormulas"
                  :loading="timesheetStore.loading.loadingFormulas"
                >
                  <v-icon left>mdi-refresh</v-icon>
                  Refresh
                </v-btn>
              </v-card-title>
              <v-card-text>
                <v-expansion-panels>
                  <v-expansion-panel
                    v-for="(formula, key) in timesheetStore.formulas"
                    :key="key"
                  >
                    <v-expansion-panel-header>
                      <strong>{{ formatFormulaKey(key) }}</strong>
                    </v-expansion-panel-header>
                    <v-expansion-panel-content>
                      <v-textarea
                        :value="formula"
                        :label="`Formula for ${formatFormulaKey(key)}`"
                        readonly
                        rows="2"
                        outlined
                      ></v-textarea>
                    </v-expansion-panel-content>
                  </v-expansion-panel>
                </v-expansion-panels>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>
      </v-container>
    </v-main>
  </v-app>
</template>

<script>
import { useTimesheetStore } from '../stores/timesheetStore'

export default {
  name: 'TimesheetProcessor',
  setup() {
    const timesheetStore = useTimesheetStore()
    return { timesheetStore }
  },
  data() {
    return {
      selectedFile: null,
      search: '',
      headers: [
        { text: 'Application', value: 'application', sortable: true },
        { text: 'Item Name', value: 'item_name', sortable: true },
        { text: 'Item Detail', value: 'item_detail', sortable: true },
        { text: 'Item Type', value: 'item_type', sortable: true },
        { text: 'Team Name', value: 'team_name', sortable: true },
        { text: 'Log Owner', value: 'log_owner', sortable: true },
        { text: 'Log Date', value: 'formattedLogDate', sortable: true },
        { text: 'Log Hours', value: 'log_hours_decimal', sortable: true },
        { text: 'Status', value: 'status', sortable: true },
        { text: 'Sprint', value: 'sprint', sortable: true },
        { text: 'Estimated Points', value: 'estimated_points', sortable: true },
        { text: 'Actual Points', value: 'actual_points', sortable: true },
        { text: 'Weekly Points', value: 'weekly_points', sortable: true },
        { text: 'Lead Time', value: 'lead_time', sortable: true },
        { text: 'Cycle Time', value: 'cycle_time', sortable: true },
        { text: 'Defects Density', value: 'defects_density', sortable: true },
        { text: 'Story Point Accuracy', value: 'story_point_accuracy', sortable: true },
        { text: 'Release Delay', value: 'release_delay', sortable: true }
      ]
    }
  },
  async mounted() {
    // Initialize the store when component mounts
    await this.timesheetStore.initializeStore()
  },
  methods: {
    handleFileChange() {
      // Clear any previous messages when a new file is selected
      this.timesheetStore.clearMessage()
    },

    async handleUpload() {
      await this.timesheetStore.uploadTimesheetFile(this.selectedFile)
    },

    async handleDownload() {
      await this.timesheetStore.downloadProcessedFile()
    },

    async handleClearData() {
      const confirmed = confirm('Are you sure you want to clear all timesheet data?')
      if (confirmed) {
        await this.timesheetStore.clearAllData()
      }
    },

    async refreshFormulas() {
      await this.timesheetStore.loadFormulas()
    },

    formatFormulaKey(key) {
      return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
    },

    getItemTypeColor(itemType) {
      const colors = {
        'Planned': 'green',
        'BUG': 'red',
        'New request': 'blue',
        'Task': 'orange',
        'Story': 'purple'
      }
      return colors[itemType] || 'grey'
    },

    getStatusColor(status) {
      const colors = {
        'Done': 'green',
        'In Progress': 'orange',
        'To Do': 'grey',
        'Pending': 'amber'
      }
      return colors[status] || 'grey'
    }
  }
}
</script>

<style scoped>
.v-card {
  margin-bottom: 20px;
}

.text-h4 {
  font-weight: bold;
}

.v-data-table {
  font-size: 0.875rem;
}

.v-chip {
  font-size: 0.75rem;
}

/* Loading states */
.v-btn--loading {
  pointer-events: none;
}

/* Enhanced button spacing */
.v-card-actions .v-btn:not(:last-child) {
  margin-right: 8px;
}

/* Improved alert styling */
.v-alert {
  margin-bottom: 16px;
}
</style>