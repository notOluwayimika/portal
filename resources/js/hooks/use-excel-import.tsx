import { useCallback } from 'react';
import * as XLSX from 'xlsx';
import { toast } from 'sonner'

interface ImportResult {
  file: File;
  jsonData: Record<string, unknown>[];
  extension: string;
}

type ImportCallback = (result: ImportResult) => void;

export const useExcelImport = () => {
  const importExcelData = useCallback(
    (event: React.ChangeEvent<HTMLInputElement>, callback: ImportCallback) => {
      const file = event.target.files?.[0];
      if (!file) return;

      const ext = file.name.match(/[^.]+$/)?.[0].toLowerCase();

      if (!ext || !['xls', 'xlsx', 'csv'].includes(ext)) {
        toast.warning('File MUST be a valid excel file');
        return;
      }

      const reader = new FileReader();

      reader.onload = (e) => {
        const data = new Uint8Array(e.target?.result as ArrayBuffer);
        const workbook = XLSX.read(data, { type: 'array' });
        const jsonData = XLSX.utils.sheet_to_json<Record<string, unknown>>(
          workbook.Sheets[workbook.SheetNames[0]]
        );

        const arrayOfObjectsWithLowercaseKeys = jsonData.map((obj) =>
          Object.fromEntries(
            Object.entries(obj).map(([key, value]) => [
              key.toLowerCase().replace(' ', '_'),
              value,
            ])
          )
        );

        callback({
          file,
          jsonData: arrayOfObjectsWithLowercaseKeys,
          extension: ext,
        });
      };

      reader.readAsArrayBuffer(file);
    },
    []
  );

  return { importExcelData };
};