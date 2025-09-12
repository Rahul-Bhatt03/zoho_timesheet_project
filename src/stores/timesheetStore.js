// stores/timesheetStore.js
import { defineStore } from 'pinia'
import { timesheetApi } from '../api/timesheetApi'

export const useTimesheetStore = defineStore('timesheet', {
  state: () => ({
    entries: [],
    averages: {},
    totalEntries: 0,
    formulas: {},
    loading: {
      uploading: false,
      downloading: false,
      clearing: false,
      loadingData: false,
      loadingFormulas: false
    },
    message: {
      text: '',
      type: 'info'
    }
  }),

  getters: {
    hasData: (state) => state.entries && state.entries.length > 0,
    
    isUploading: (state) => state.loading.uploading,
    
    isDownloading: (state) => state.loading.downloading,
    
    isClearing: (state) => state.loading.clearing,
    
    currentMessage: (state) => state.message,
    
    processedEntries: (state) => {
      return state.entries.map(entry => ({
        ...entry,
        formattedLogDate: entry.log_date ? new Date(entry.log_date).toLocaleDateString() : '',
        formattedRequestedDate: entry.requested_date ? new Date(entry.requested_date).toLocaleDateString() : '',
        formattedExpectedStartDate: entry.expected_start_date ? new Date(entry.expected_start_date).toLocaleDateString() : '',
        formattedExpectedReleaseDate: entry.expected_release_date ? new Date(entry.expected_release_date).toLocaleDateString() : '',
        formattedActualStartDate: entry.actual_start_date ? new Date(entry.actual_start_date).toLocaleDateString() : '',
        formattedActualReleaseDate: entry.actual_release_date ? new Date(entry.actual_release_date).toLocaleDateString() : ''
      }))
    },
    
    summaryStats: (state) => ({
      totalEntries: state.totalEntries,
      averageLeadTime: state.averages.average_lead_time || 0,
      averageCycleTime: state.averages.average_cycle_time || 0,
      totalWeeklyPoints: state.averages.total_weekly_points || 0,
      totalEstimatedPoints: state.averages.total_estimated_points || 0,
      totalActualPoints: state.averages.total_actual_points || 0,
      averageStoryPointAccuracy: state.averages.average_story_point_accuracy || 0
    })
  },

  actions: {
    // Message management
    setMessage(text, type = 'info') {
      this.message = { text, type }
    },

    clearMessage() {
      this.message = { text: '', type: 'info' }
    },

    // Upload timesheet file
    async uploadTimesheetFile(file) {
      if (!file) {
        this.setMessage('Please select a file first', 'error')
        return { success: false, message: 'No file selected' }
      }

      this.loading.uploading = true
      this.clearMessage()

      try {
        const result = await timesheetApi.uploadTimesheet(file)
        
        if (result.success) {
          this.entries = result.data.entries
          this.averages = result.data.averages
          this.totalEntries = result.data.total_entries
          this.setMessage(result.message, 'success')
        } else {
          this.setMessage(result.message, 'error')
        }

        return result
      } catch (error) {
        const errorMessage = error.response?.data?.message || 'Error uploading file'
        this.setMessage(errorMessage, 'error')
        return { success: false, message: errorMessage }
      } finally {
        this.loading.uploading = false
      }
    },

    // Load existing data
    async loadExistingData() {
      this.loading.loadingData = true

      try {
        const result = await timesheetApi.getTimesheetData()
        
        if (result.success && result.data.entries.length > 0) {
          this.entries = result.data.entries
          this.averages = result.data.averages
          this.totalEntries = result.data.total_entries
        }

        return result
      } catch (error) {
        console.error('Load data error:', error)
        return { success: false, message: 'Error loading existing data' }
      } finally {
        this.loading.loadingData = false
      }
    },

    // Load formulas
    async loadFormulas() {
      this.loading.loadingFormulas = true

      try {
        const result = await timesheetApi.getFormulas()
        
        if (result.success) {
          this.formulas = result.data
        }

        return result
      } catch (error) {
        console.error('Load formulas error:', error)
        return { success: false, message: 'Error loading formulas' }
      } finally {
        this.loading.loadingFormulas = false
      }
    },

    // Download processed file
    async downloadProcessedFile() {
      if (!this.hasData) {
        this.setMessage('No data available to download', 'error')
        return { success: false, message: 'No data available' }
      }

      this.loading.downloading = true

      try {
        const blob = await timesheetApi.downloadProcessedFile()
        
        // Create download link
        const url = window.URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `processed_timesheet_${new Date().toISOString().split('T')[0]}.xlsx`)
        document.body.appendChild(link)
        link.click()
        link.remove()
        window.URL.revokeObjectURL(url)
        
        this.setMessage('File downloaded successfully', 'success')
        return { success: true, message: 'File downloaded successfully' }
      } catch (error) {
        const errorMessage = 'Error downloading file'
        this.setMessage(errorMessage, 'error')
        return { success: false, message: errorMessage }
      } finally {
        this.loading.downloading = false
      }
    },

    // Clear all data
    async clearAllData() {
      this.loading.clearing = true

      try {
        const result = await timesheetApi.clearTimesheetData()
        
        if (result.success) {
          this.entries = []
          this.averages = {}
          this.totalEntries = 0
          this.setMessage(result.message, 'success')
        } else {
          this.setMessage(result.message || 'Error clearing data', 'error')
        }

        return result
      } catch (error) {
        const errorMessage = 'Error clearing data'
        this.setMessage(errorMessage, 'error')
        return { success: false, message: errorMessage }
      } finally {
        this.loading.clearing = false
      }
    },

    // Initialize store data
    async initializeStore() {
      await Promise.all([
        this.loadExistingData(),
        this.loadFormulas()
      ])
    }
  }
})