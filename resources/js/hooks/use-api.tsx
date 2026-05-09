import { useState, useCallback, useRef } from 'react';
import axiosInstance from 'axios';
import type { ApiResponse, ApiConfig, ApiError } from '@/types/api.types';

// Helper type to handle both response types
type ApiResult<T> = ApiResponse<T> | T;
type HttpMethod = 'get' | 'post' | 'put' | 'delete' | 'patch';

// Check if response follows ApiResponse structure
function isApiResponse<T>(response: any): response is ApiResponse<T> {
  return (
    response &&
    typeof response === 'object' &&
    'status' in response &&
    'data' in response
  );
}

export function useApi<T = any>() {
  // State
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isSuccess, setIsSuccess] = useState<boolean>(false);
  const [rawResponse, setRawResponse] = useState<any>(null);

  // Execute API request
  const execute = useCallback(
    async (
      method: HttpMethod,
      url: string,
      payload?: any,
      config?: ApiConfig
    ): Promise<ApiResult<T>> => {
      // Reset state
      setIsLoading(true);
      setError(null);
      setIsSuccess(false);
      setData(null);
      setRawResponse(null);

      try {
        // Note: axios interceptor returns response.data (the body), not the AxiosResponse object
        const body = (await axiosInstance.request({
          method,
          url,
          data: payload,
          ...config,
        })) as any;

        // Store raw response (body)
        setRawResponse(body);

        // 1. Check if response follows ApiResponse structure (explicit status and data)
        if (isApiResponse<T>(body)) {
          if (body.status === 'error') {
            const apiError: ApiError = {
              message: body.message || 'API returned error status',
              status: 'error',
              errors: (body as any).errors,
            };
            setError(apiError);
            throw apiError;
          }

          setData(body.data);
          setIsSuccess(true);
          return body;
        }

        // 2. Handle Laravel Resource structure ({ data: ... } but maybe no status)
        if (body && typeof body === 'object' && 'data' in body) {
          setData(body.data);
          setIsSuccess(true);
          return body as T;
        }

        // 3. Handle Plain Array or other direct data
        setData(body);
        setIsSuccess(true);

        const wrappedResponse = {
          data: body,
          status: 'success',
          message: 'Success',
        };

        return wrappedResponse as unknown as T;
      } catch (err: any) {
        // Handle errors
        if (err.response?.data) {
          if (
            isApiResponse(err.response.data) &&
            err.response.data.status === 'error'
          ) {
            const apiError = err.response.data as ApiError;
            setError(apiError);
            throw apiError;
          } else {
            const apiError: ApiError = {
              message:
                err.response.data?.message ||
                err.response.data ||
                'Unknown error',
              status: 'error',
              errors: err.response.data?.errors,
            };
            setError(apiError);
            throw apiError;
          }
        } else if (err.status === 'error') {
          // already handled api error
          throw err;
        } else if (err.request) {
          // No response received
          const networkError: ApiError = {
            message: 'Network error: No response from server',
            status: 'error',
          };
          setError(networkError);
          throw networkError;
        } else {
          // Request setup error
          const requestError: ApiError = {
            message: err.message || 'Request failed',
            status: 'error',
          };
          setError(requestError);
          throw requestError;
        }
      } finally {
        setIsLoading(false);
      }
    },
    []
  );

  // Convenience methods
  const get = useCallback(
    (url: string, config?: ApiConfig): Promise<ApiResult<T>> =>
      execute('get', url, undefined, config),
    [execute]
  );

  const post = useCallback(
    (url: string, payload?: any, config?: ApiConfig): Promise<ApiResult<T>> =>
      execute('post', url, payload, config),
    [execute]
  );

  const put = useCallback(
    (url: string, payload?: any, config?: ApiConfig): Promise<ApiResult<T>> =>
      execute('put', url, payload, config),
    [execute]
  );

  const del = useCallback(
    (url: string, config?: ApiConfig): Promise<ApiResult<T>> =>
      execute('delete', url, undefined, config),
    [execute]
  );

  const patch = useCallback(
    (url: string, payload?: any, config?: ApiConfig): Promise<ApiResult<T>> =>
      execute('patch', url, payload, config),
    [execute]
  );

  // Reset state
  const reset = useCallback((): void => {
    setData(null);
    setError(null);
    setIsLoading(false);
    setIsSuccess(false);
    setRawResponse(null);
  }, []);

  return {
    // State
    data,
    error,
    isLoading,
    isSuccess,
    rawResponse,

    // Methods
    get,
    post,
    put,
    delete: del,
    patch,
    execute,
    reset,
  };
}