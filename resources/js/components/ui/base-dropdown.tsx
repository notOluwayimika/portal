import { useState, useRef, useEffect, useCallback, ReactNode } from 'react'
import ReactDOM from 'react-dom'

interface Option {
  [key: string]: unknown
}

interface SelectProps {
  value?: string | number | null
  onChange?: (value: string | number | null | undefined) => void
  options: (string | number | Option)[]
  labelField?: string
  valueField?: string
  placeholder?: string
  label?: string
  buttonClass?: string
  dropdownClass?: string
  disabled?: boolean
  showIndicator?: boolean
  getIndicatorClass?: (value: unknown) => string
  formatLabel?: (label: string) => string
  renderOption?: (option: NormalizedOption) => ReactNode
  header?: ReactNode
  footer?: ReactNode
  icon?: (isOpen: boolean) => ReactNode
}

interface NormalizedOption {
  label: string
  value: string | number | null | undefined
  original: unknown
}

const DEFAULT_BUTTON_CLASS =
  'flex items-center gap-2 text-xs font-medium transition-all w-full justify-between ' +
  'px-4 py-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 ' +
  'text-slate-700 dark:text-slate-200 rounded-lg shadow-sm hover:border-blue-500 ' +
  'focus:ring-2 focus:ring-blue-500/20'

const DEFAULT_DROPDOWN_CLASS =
  'min-w-fit bg-white dark:bg-slate-800 rounded-lg shadow-xl ' +
  'border border-slate-100 dark:border-slate-700 py-1 z-[9999] overflow-hidden'

export default function Select({
  value,
  onChange,
  options = [],
  labelField = 'label',
  valueField = 'value',
  placeholder = 'Select...',
  label,
  buttonClass = DEFAULT_BUTTON_CLASS,
  dropdownClass = DEFAULT_DROPDOWN_CLASS,
  disabled = false,
  showIndicator = false,
  getIndicatorClass,
  formatLabel,
  renderOption,
  header,
  footer,
  icon,
}: SelectProps) {
  const [isOpen, setIsOpen] = useState(false)
  const [dropdownStyle, setDropdownStyle] = useState<React.CSSProperties>({})
  const triggerRef = useRef<HTMLDivElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)

  // Normalize options to a consistent shape
  const normalizedOptions: NormalizedOption[] = options.map(option => {
    if (typeof option === 'object' && option !== null) {
      const opt = option as Record<string, unknown>
      return {
        label: String(opt[labelField] ?? ''),
        value: opt[valueField] as string | number | null | undefined,
        original: option,
      }
    }
    return { label: String(option), value: option as string | number, original: option }
  })

  const selectedOption =
    value != null
      ? normalizedOptions.find(opt => {
          if (typeof opt.value === 'string' && typeof value === 'string') {
            return opt.value.trim().toLowerCase() === value.trim().toLowerCase()
          }
          return opt.value === value
        })
      : undefined

  const displayLabel = (lbl: string) => (formatLabel ? formatLabel(lbl) : lbl)

  // Compute dropdown position relative to trigger (mirrors Vue's useElementBounding + teleport)
  const updateDropdownPosition = useCallback(() => {
    if (!triggerRef.current) return
    const rect = triggerRef.current.getBoundingClientRect()
    setDropdownStyle({
      position: 'fixed',
      top: rect.bottom + 4,
      left: rect.left,
      width: rect.width,
      zIndex: 9999,
    })
  }, [])

  const toggleDropdown = () => {
    if (disabled) return
    if (!isOpen) updateDropdownPosition()
    setIsOpen(prev => !prev)
  }

  const handleSelect = (option: NormalizedOption) => {
    setIsOpen(false)
    onChange?.(option.value)
  }

  // Close on outside click (mirrors onMounted/onUnmounted listeners)
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      const target = e.target as Node
      const outsideTrigger = triggerRef.current && !triggerRef.current.contains(target)
      const outsideContent = contentRef.current && !contentRef.current.contains(target)
      if (outsideTrigger && outsideContent) setIsOpen(false)
    }
    document.addEventListener('click', handleClickOutside)
    return () => document.removeEventListener('click', handleClickOutside)
  }, [])

  return (
    <div className="relative inline-block w-full" ref={triggerRef}>
      <button
        type="button"
        onClick={toggleDropdown}
        disabled={disabled}
        className={`${buttonClass} ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
      >
        <div className="flex items-center gap-2 overflow-hidden">
          {showIndicator && selectedOption && getIndicatorClass && (
            <div
              className={`w-2 h-2 rounded-full shrink-0 ${getIndicatorClass(selectedOption.value)}`}
            />
          )}
          <span className="truncate">
            {label || (selectedOption ? displayLabel(selectedOption.label) : placeholder)}
          </span>
        </div>

        {icon ? (
          icon(isOpen)
        ) : (
          <ChevronDownIcon
            className={`w-3.5 h-3.5 shrink-0 transition-transform duration-200 opacity-80 ${
              isOpen ? 'rotate-180' : ''
            }`}
          />
        )}
      </button>

      {/* Teleport equivalent: render into a portal at document.body */}
      {isOpen &&
        ReactDOM.createPortal(
          <div
            ref={contentRef}
            className={`origin-top-left ${dropdownClass}`}
            style={dropdownStyle}
          >
            <div className="max-h-60 overflow-y-auto custom-scrollbar">
              {header}
              {normalizedOptions.map(option => (
                <button
                  key={String(option.value)}
                  onClick={() => handleSelect(option)}
                  className={`w-full text-left px-3 py-2 text-xs transition-colors flex items-center gap-2 ${
                    value === option.value
                      ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 font-semibold'
                      : 'text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700/50'
                  }`}
                >
                  {showIndicator && getIndicatorClass && (
                    <div
                      className={`w-1.5 h-1.5 rounded-full shrink-0 ${getIndicatorClass(option.value)}`}
                    />
                  )}
                  {renderOption ? (
                    renderOption(option)
                  ) : (
                    <span className="truncate">{displayLabel(option.label)}</span>
                  )}
                </button>
              ))}
              {footer}
            </div>
          </div>,
          document.body
        )}
    </div>
  )
}

// Inline chevron icon (replace with lucide-react's ChevronDown if available)
function ChevronDownIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}
