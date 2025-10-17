import * as React from "react";
import { Table, TableHeader, TableBody, TableHead, TableRow, TableCell } from "@/components/ui/table";

export interface DataTableColumn<T> {
    accessor: keyof T;
    header: string;
    cell?: (value: unknown, row: T) => React.ReactNode;
}

export interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    data: T[];
    rowDisabled?: (row: T) => boolean;
    rowSelected?: (row: T) => boolean;
    onRowSelect?: (row: T) => void;
    radio?: boolean;
    checkbox?: boolean;
    multiSelect?: boolean;
}

export function DataTable<T extends { id: number }>({ columns, data, rowDisabled, rowSelected, onRowSelect, radio, checkbox, multiSelect }: DataTableProps<T>) {
    const showSelector = radio || checkbox || multiSelect;
    const useCheckbox = checkbox || multiSelect;

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {showSelector && <TableHead>Select</TableHead>}
                    {columns.map(col => (
                        <TableHead key={String(col.accessor)}>{col.header}</TableHead>
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {data.map(row => {
                    const disabled = rowDisabled?.(row);
                    const selected = rowSelected?.(row);
                    return (
                        <TableRow key={row.id} className={disabled ? "bg-gray-100 text-gray-400" : selected ? "bg-gray-800" : ""}>
                            {showSelector && (
                                <TableCell className="text-center">
                                    <input
                                        type={useCheckbox ? "checkbox" : "radio"}
                                        name={useCheckbox ? undefined : "datatable-radio"}
                                        checked={selected}
                                        onChange={() => !disabled && onRowSelect?.(row)}
                                        disabled={disabled}
                                    />
                                </TableCell>
                            )}
                            {columns.map(col => (
                                <TableCell key={String(col.accessor)}>
                                    {col.cell ? col.cell(row[col.accessor], row) : (row[col.accessor] as React.ReactNode)}
                                </TableCell>
                            ))}
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}
