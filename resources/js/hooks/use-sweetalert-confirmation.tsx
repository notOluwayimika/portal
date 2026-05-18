import { createContext, useCallback, useContext, useState, type ReactNode } from 'react'
import { withSwal } from 'react-sweetalert2'
import { useApi } from './use-api'
import { toast } from 'sonner'
import type { ApiConfig } from '@/types/api.types'

interface ApiSweetAlertOptions {
  // Option 1: API call params
  method?: 'get' | 'post' | 'put' | 'delete' | 'patch'
  url?: string
  payload?: any
  config?: ApiConfig

  // Option 2: Custom function
  onConfirm?: () => Promise<any> | any

  // SweetAlert options
  sweetAlertTitle?: string
  sweetAlertText?: string
  sweetAlertIcon?: 'warning' | 'info' | 'success' | 'error' | 'question'
  confirmButtonText?: string
  cancelButtonText?: string
  showSuccessAlert?: boolean
  showErrorAlert?: boolean
  successMessage?: string
}

// --- Context that holds the swal instance from withSwal ---
const SwalCtx = createContext<any>(null)

const SwalProviderInner = withSwal(({ swal, children }: any) => (
  <SwalCtx.Provider value={swal}>{children}</SwalCtx.Provider>
))

/**
 * Render this once near the root of your app (or around the relevant subtree)
 * so that useApiSweetAlertConfirmation can access the swal instance.
 */
export function SwalProvider({ children }: { children: ReactNode }) {
  return <SwalProviderInner>{children}</SwalProviderInner>
}

export function useApiSweetAlertConfirmation<T = any>() {
  const swal = useContext(SwalCtx)
  const api = useApi<T>()
  const [loading, setLoading] = useState(false)

  const confirmAndExecute = useCallback(
    async (options: ApiSweetAlertOptions): Promise<any | false> => {
      if (!swal) throw new Error('useApiSweetAlertConfirmation requires <SwalProvider> in the tree')

      const {
        method = 'post',
        url,
        payload,
        config,
        onConfirm,
        sweetAlertTitle = 'Are you sure?',
        sweetAlertText = 'This action cannot be undone!',
        sweetAlertIcon = 'warning',
        confirmButtonText = 'Yes',
        cancelButtonText = 'Cancel',
        showSuccessAlert = true,
        showErrorAlert = true,
        successMessage = 'Operation completed successfully.',
      } = options

      const result = await swal.fire({
        title: sweetAlertTitle,
        text: sweetAlertText,
        icon: sweetAlertIcon,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
      })

      if (result.isConfirmed) {
        try {
          setLoading(true)
          let response

          if (onConfirm) {
            response = await onConfirm()
          } else if (url) {
            response = (await api.execute(method, url, payload, config)) as any
          } else {
            throw new Error('Either url or onConfirm must be provided')
          }

          if (showSuccessAlert) {
            toast.success(response?.message || successMessage)
          }

          return response
        } catch (err: any) {
          if (showErrorAlert) {
            toast.error(err.message || 'Something went wrong.')
          }
          return false
        } finally {
          setLoading(false)
        }
      }

      return false
    },
    [api, swal]
  )

  return {
    confirmAndExecute,
    api,
    loading,
  }
}
