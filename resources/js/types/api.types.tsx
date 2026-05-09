import type { AxiosRequestConfig } from "axios";

// Generic API response type
export interface ApiResponse<T = any> {
  data?: T;
  message?: string;
  status: 'success' | 'error' | number;
}

export interface ApiError {
  message: string;
  status: 'error' | number;
  errors?: Record<string, string[]> | Array<{ transaction_id: number; error: string }>;
}

// API configuration
export interface ApiConfig extends AxiosRequestConfig {
  skipAuth?: boolean;
  retry?: boolean;
}
