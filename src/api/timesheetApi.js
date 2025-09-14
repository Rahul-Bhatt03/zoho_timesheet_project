import axios from 'axios'

// Create axios instance with default config
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/timesheet',
  timeout: 60000, // Increased to 60 seconds for large files
  headers: {
    'Content-Type': 'application/json'
  }
})

// Request interceptor
apiClient.interceptors.request.use(
  (config) => {
    console.log('Making request to:', config.baseURL + config.url);
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor
apiClient.interceptors.response.use(
  (response) => {
    // Don't parse blob responses
    if (response.config.responseType === 'blob') {
      return response;
    }
    return response.data
  },
  (error) => {
    console.error('API Error:', error);
    
    if (error.code === 'ECONNABORTED') {
      console.error('Request timeout')
    }
    
    if (error.response?.status === 401) {
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
   */
  async getTimesheetData() {
    return await apiClient.get('/data')
  },

  /**
   * Get formula configurations
   */
  async getFormulas() {
    return await apiClient.get('/formulas')
  },

  /**
   * Download processed Excel file - FIXED VERSION
   */
/**
 * Download processed Excel file - Updated for new backend response
 */
async downloadProcessedFile() {
  try {
    console.log('Starting download request...');
    console.log('Request URL:', apiClient.defaults.baseURL + '/download');
    
    const response = await apiClient.get('/download', {
      // Remove responseType: 'blob' since we're now getting JSON
      headers: {
        'Accept': 'application/json'
      },
      timeout: 60000
    });

    console.log('Download response received:', response);

    // Check if the response is successful
    if (!response.success) {
      throw new Error(response.message || 'Failed to generate Excel file');
    }

    // Return the response data which contains file info
    return response.data;
      
  } catch (error) {
    console.error('Download error details:', error);
    throw error;
  }
},

  /**
   * Clear all timesheet data
   */
  async clearTimesheetData() {
    return await apiClient.delete('/clear')
  },

  /**
   * Update formula configuration
   */
  async updateFormulas(formulas) {
    return await apiClient.put('/formulas', { formulas })
  },

  /**
   * Get timesheet data with filters
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
   */
  async getAnalytics(dateRange = {}) {
    return await apiClient.post('/analytics', dateRange)
  },

  /**
   * Validate timesheet file before upload
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
   */
  async getUploadHistory(limit = 10) {
    return await apiClient.get(`/history?limit=${limit}`)
  },

  /**
   * Delete specific timesheet entry
   */
  async deleteEntry(entryId) {
    return await apiClient.delete(`/entry/${entryId}`)
  },

  /**
   * Update specific timesheet entry
   */
  async updateEntry(entryId, entryData) {
    return await apiClient.put(`/entry/${entryId}`, entryData)
  }
}