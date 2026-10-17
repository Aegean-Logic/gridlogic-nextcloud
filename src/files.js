// Files app: an "Open in GridCraft" action on spreadsheets, the default (click to open) for them.

import { DefaultType, Permission, registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'

const WORKBOOKS = ['xlsx', 'xlsm', 'ods', 'xls', 'xlsb']
const TEXT_TABLES = ['csv', 'tsv']

// Original icon: a 3×3 grid.
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h18v18H3V3zm2 2v4h4V5H5zm6 0v4h4V5h-4zm6 0v4h2V5h-2zM5 11v4h4v-4H5zm6 0v4h4v-4h-4zm6 0v4h2v-4h-2zM5 17v2h4v-2H5zm6 0v2h4v-2h-4zm6 0v2h2v-2h-2z"/></svg>'

function extension(node) {
	const name = node.basename ?? ''
	const i = name.lastIndexOf('.')
	return i < 0 ? '' : name.slice(i + 1).toLowerCase()
}

function opens(node) {
	const ext = extension(node)
	return node.type === 'file' && (WORKBOOKS.includes(ext) || TEXT_TABLES.includes(ext)) && (node.permissions & Permission.READ) !== 0
}

registerFileAction({
	id: 'gridcraft-open',
	displayName: () => 'Open in GridCraft',
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => nodes.length === 1 && opens(nodes[0]),
	async exec({ nodes }) {
		const url = new URL(generateUrl('/apps/gridcraft/edit'), location.href)
		url.searchParams.set('path', nodes[0].path)
		window.location.assign(url.toString())
		return null
	},
	order: -10,
	default: DefaultType.DEFAULT,
})
