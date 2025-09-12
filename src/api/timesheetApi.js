import axios from 'axios'

// Create axios instance with default config
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL ||'/api/timesheet',
  timeout: 30000, // 30 seconds timeout
  headers: {
    'Content-Type': 'application/json'
  }
})

// Request interceptor
apiClient.interceptors.request.use(
  (config) => {
    // Add any auth headers or other request modifications here
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor
apiClient.interceptors.response.use(
  (response) => {
    return response.data
  },
  (error) => {
    // Handle common error scenarios
    if (error.code === 'ECONNABORTED') {
      console.error('Request timeout')
    }
    
    if (error.response?.status === 401) {
      // Handle unauthorized access
      console.error('Unauthorized access')
    }
    
    if (error.response?.status >= 500) {
      console.error('Server error:', error.response.status)
    }
    
    return Promise.reject(error)
  }
)

export const timesheetApi = {
  /**
   * Upload timesheet file
   * @param {File} file - The timesheet file to upload
   * @returns {Promise<Object>} Response with processed data
   */
  async uploadTimesheet(file) {
    const formData = new FormData()
    formData.append('timesheet', file)

    return await apiClient.post('/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      },
      onUploadProgress: (progressEvent) => {
        const percentCompleted = Math.round((progressEvent.loaded * 100) / progressEvent.total)
        console.log(`Upload Progress: ${percentCompleted}%`)
      }
    })
  },

  /**
   * Get existing timesheet data
   * @returns {Promise<Object>} Existing timesheet data
   */
  async getTimesheetData() {
    return await apiClient.get('/data')
  },

  /**
   * Get formula configurations
   * @returns {Promise<Object>} Formula configurations
   */
  async getFormulas() {
    return await apiClient.get('/formulas')
  },

  /**
   * Download processed Excel file
   * @returns {Promise<Blob>} Excel file as blob
   */
  async downloadProcessedFile() {
    const response = await axios.get('/api/timesheet/download', {
      responseType: 'blob',
      timeout: 60000 // Increase timeout for file downloads
    })
    
    return response.data
  },

  /**
   * Clear all timesheet data
   * @returns {Promise<Object>} Success/error response
   */
  async clearTimesheetData() {
    return await apiClient.delete('/clear')
  },

  /**
   * Update formula configuration
   * @param {Object} formulas - Formula configurations
   * @returns {Promise<Object>} Success/error response
   */
  async updateFormulas(formulas) {
    return await apiClient.put('/formulas', { formulas })
  },

  /**
   * Get timesheet data with filters
   * @param {Object} filters - Filter parameters
   * @returns {Promise<Object>} Filtered timesheet data
   */
  async getFilteredData(filters = {}) {
    const params = new URLSearchParams()
    
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params.append(key, value)
      }
    })

    return await apiClient.get(`/data?${params.toString()}`)
  },

  /**
   * Export data in different formats
   * @param {string} format - Export format (csv, excel, json)
   * @param {Object} options - Export options
   * @returns {Promise<Blob>} Exported file as blob
   */
  async exportData(format = 'excel', options = {}) {
    const response = await axios.post('/api/timesheet/export', 
      { format, ...options }, 
      {
        responseType: 'blob',
        timeout: 60000
      }
    )
    
    return response.data
  },

  /**
   * Get analytics data
   * @param {Object} dateRange - Date range for analytics
   * @returns {Promise<Object>} Analytics data
   */
  async getAnalytics(dateRange = {}) {
    return await apiClient.post('/analytics', dateRange)
  },

  /**
   * Validate timesheet file before upload
   * @param {File} file - The file to validate
   * @returns {Promise<Object>} Validation result
   */
  async validateTimesheetFile(file) {
    const formData = new FormData()
    formData.append('timesheet', file)

    return await apiClient.post('/validate', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
  },

  /**
   * Get upload history
   * @param {number} limit - Number of records to fetch
   * @returns {Promise<Object>} Upload history
   */
  async getUploadHistory(limit = 10) {
    return await apiClient.get(`/history?limit=${limit}`)
  },

  /**
   * Delete specific timesheet entry
   * @param {string|number} entryId - Entry ID to delete
   * @returns {Promise<Object>} Success/error response
   */
  async deleteEntry(entryId) {
    return await apiClient.delete(`/entry/${entryId}`)
  },

  /**
   * Update specific timesheet entry
   * @param {string|number} entryId - Entry ID to update
   * @param {Object} entryData - Updated entry data
   * @returns {Promise<Object>} Updated entry data
   */
  async updateEntry(entryId, entryData) {
    return await apiClient.put(`/entry/${entryId}`, entryData)
  }
}