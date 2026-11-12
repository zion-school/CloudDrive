/*
 * Copyright (c) 2026 Zion Nursery & Primary School, Kovaipudur
 * SPDX-License-Identifier: MIT
 * Date: 09-Nov-2026
 */
'use strict';
// The 3D viewer of plugins/model, loaded by main.js the first time a model is opened. WebGL 2, no libraries.
// Every format becomes either triangles – { meshes: [{ pos, col?, uv?, image?, color, cut }] } – or toolpaths
// – { gcode } – Z-up, in millimetres where the format says so. Faces are flat-shaded from screen-space derivatives,
// so no normals are stored. Drawing happens only when something changes.
(() => {
    const { esc, fileUrl, fmtSize } = CD.ui;
    const BLUE = 0x4f8fd8;   // models without colours of their own (as the thumbnails)
    const utf8 = new TextDecoder();

    /** A typed array that grows as values are pushed. */
    class Grow {
        constructor(T, n = 4096) {
            this.a = new T(n);
            this.n = 0;
        }
        need(k) {
            if (this.n + k > this.a.length) {
                const b = new this.a.constructor(Math.max(this.a.length * 2, this.n + k));
                b.set(this.a);
                this.a = b;
            }
        }
        push3(x, y, z) {
            this.need(3);
            this.a[this.n++] = x;
            this.a[this.n++] = y;
            this.a[this.n++] = z;
        }
        push(...v) {
            this.need(v.length);
            for (const x of v)
                this.a[this.n++] = x;
        }
        done() {
            return this.a.slice(0, this.n);
        }
    }
    const rgb = c => [c >> 16 & 255, c >> 8 & 255, c & 255];
    const hex = s => (/^#[0-9a-f]{6}/i.test(s || '') ? parseInt(s.slice(1, 7), 16) : null);

    // ---------- Loading ----------

    async function download(item, signal, progress) {
        const r = await fetch(fileUrl(item), { signal });
        if (!r.ok)
            throw new Error((await r.text().catch(() => '')).slice(0, 200) || `Could not load the file (${r.status})`);
        const total = +r.headers.get('Content-Length') || item.size || 0, parts = [], reader = r.body.getReader();
        let got = 0, shown = 0;
        for (;;) {
            const { done, value } = await reader.read();
            if (done)
                break;
            parts.push(value);
            got += value.length;
            if (performance.now() - shown > 100) {
                progress(got, total);
                shown = performance.now();
            }
        }
        if (parts.length === 1)
            return parts[0];
        const b = new Uint8Array(got);
        let o = 0;
        for (const p of parts) {
            b.set(p, o);
            o += p.length;
        }
        return b;
    }

    // ---------- STL: binary (84 + 50 bytes a triangle – often with a "solid" header too) or ASCII ----------

    function readStl(b) {
        const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
        const n = b.length >= 84 ? dv.getUint32(80, true) : 0;
        const head = utf8.decode(b.subarray(0, 1024));
        if (84 + 50 * n !== b.length && /^\s*solid\b/.test(head) && head.includes('facet')) {
            const pos = new Grow(Float32Array), re = /vertex\s+(\S+)\s+(\S+)\s+(\S+)/g, text = utf8.decode(b);
            for (let m; (m = re.exec(text));)
                pos.push3(+m[1], +m[2], +m[3]);
            pos.n -= pos.n % 9;
            return { meshes: [{ pos: pos.done(), color: BLUE }], unit: 'mm' };
        }
        const k = Math.min(n, Math.floor((b.length - 84) / 50)), pos = new Float32Array(9 * k);
        for (let i = 0, o = 96; i < k; i++, o += 50)   // skip each stored normal
            for (let j = 0; j < 9; j++)
                pos[9 * i + j] = dv.getFloat32(o + 4 * j, true);
        return { meshes: [{ pos, color: BLUE }], unit: 'mm' };
    }

    // ---------- ZIP (for 3MF): central directory + the browser's own deflate ----------

    function zipIndex(b) {
        const dv = new DataView(b.buffer, b.byteOffset, b.byteLength), map = new Map();
        let e = b.length - 22;
        while (e >= Math.max(0, b.length - 65557) && dv.getUint32(e, true) !== 0x06054b50)
            e--;
        if (e < 0 || dv.getUint32(e, true) !== 0x06054b50)
            throw new Error('This file is damaged or not a 3MF file');
        for (let p = dv.getUint32(e + 16, true), n = dv.getUint16(e + 10, true); n-- > 0 && dv.getUint32(p, true) === 0x02014b50;) {
            const nl = dv.getUint16(p + 28, true), name = utf8.decode(b.subarray(p + 46, p + 46 + nl));
            map.set(name.toLowerCase(), { method: dv.getUint16(p + 10, true), size: dv.getUint32(p + 20, true), at: dv.getUint32(p + 42, true) });
            p += 46 + nl + dv.getUint16(p + 30, true) + dv.getUint16(p + 32, true);
        }
        return {
            names: [...map.keys()],
            async get(name) {
                const z = map.get(name.replace(/^\//, '').toLowerCase());
                if (!z)
                    return null;
                const s = z.at + 30 + dv.getUint16(z.at + 26, true) + dv.getUint16(z.at + 28, true), data = b.subarray(s, s + z.size);
                if (z.method === 0)
                    return data;
                if (z.method !== 8)
                    throw new Error('This file uses a compression method the viewer does not know');
                return new Uint8Array(await new Response(new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'))).arrayBuffer());
            },
        };
    }

    // ---------- 3MF: objects (meshes or components, possibly in other model files), colours, build items ----------

    const TAG = /<(\/?)(?:[\w.-]+:)?([\w.-]+)([^>]*?)(\/?)>/g, ATTR = /(?:[\w.-]+:)?([\w.-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g;
    const attrs = s => {
        const o = {};
        for (let m; (m = ATTR.exec(s));)
            o[m[1]] = m[2] ?? m[3];
        return o;
    };
    const ID12 = [1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0];
    const matrix = s => {
        const m = (s || '').trim().split(/\s+/).map(Number);
        return m.length === 12 && m.every(Number.isFinite) ? m : ID12;
    };
    const mul34 = (a, b) => {   // row-vector 3×4 matrices: $a first, then $b
        const o = [];
        for (let r = 0; r < 4; r++)
            for (let c = 0; c < 3; c++)
                o.push(a[r * 3] * b[c] + a[r * 3 + 1] * b[3 + c] + a[r * 3 + 2] * b[6 + c] + (r === 3 ? b[9 + c] : 0));
        return o;
    };

    function parseModel(xml) {
        const objects = new Map(), groups = new Map(), items = [];
        let unit = 'millimeter', obj = null, group = null, opid, oidx;
        TAG.lastIndex = 0;
        for (let m; (m = TAG.exec(xml));) {
            const [, close, name, rest, empty] = m;
            if (close) {
                if (name === 'object')
                    obj = null;
                continue;
            }
            const a = attrs(rest);
            switch (name) {
                case 'model':
                    unit = a.unit || unit;
                    break;
                case 'object':
                    [opid, oidx] = [a.pid, a.pindex];
                    obj = { v: new Grow(Float32Array), t: new Grow(Uint32Array), comp: [], col: groups.get(opid)?.[+oidx || 0] ?? BLUE };
                    objects.set(a.id, obj);
                    if (empty)
                        obj = null;
                    break;
                case 'vertex':
                    obj?.v.push3(+a.x, +a.y, +a.z);
                    break;
                case 'triangle':
                    if (obj)
                        obj.t.push(+a.v1, +a.v2, +a.v3, a.pid !== undefined || a.p1 !== undefined
                            ? groups.get(a.pid ?? opid)?.[+(a.p1 ?? oidx) || 0] ?? obj.col : obj.col);
                    break;
                case 'component':
                    obj?.comp.push([a.objectid, a.path?.replace(/^\//, '').toLowerCase(), matrix(a.transform)]);
                    break;
                case 'item':
                    items.push([a.objectid, a.path?.replace(/^\//, '').toLowerCase(), matrix(a.transform)]);
                    break;
                case 'basematerials':
                case 'colorgroup':
                    groups.set(a.id, group = []);
                    break;
                case 'base':
                case 'color':
                    group?.push(hex(a.displaycolor ?? a.color) ?? BLUE);
                    break;
            }
        }
        return { objects, items, unit };
    }

    async function read3mf(b) {
        const zip = zipIndex(b);
        const plates = zip.names.filter(n => /^metadata\/plate_\d+\.gcode$/.test(n)).sort((x, y) => parseInt(x.slice(15)) - parseInt(y.slice(15)));
        if (plates.length) {   // a sliced project (Bambu Studio, Orca): show the print
            const g = readGcode(await zip.get(plates[0]));
            if (plates.length > 1)
                g.note = `Plate 1 of ${plates.length}`;
            return g;
        }
        const rels = utf8.decode(await zip.get('_rels/.rels') || new Uint8Array());
        const root = (/<Relationship\b[^>]*Type="[^"]*\/3dmodel"[^>]*>/i.exec(rels)?.[0].match(/Target="\/?([^"]+)"/)?.[1] || '3D/3dmodel.model').toLowerCase();
        const parts = new Map();
        for (const n of zip.names.filter(n => n.endsWith('.model')))
            parts.set(n, parseModel(utf8.decode(await zip.get(n))));
        const main = parts.get(root);
        if (!main)
            throw new Error('This 3MF file has no model in it');

        const scale = { micron: 0.001, millimeter: 1, centimeter: 10, inch: 25.4, foot: 304.8, meter: 1000 }[main.unit] ?? 1;
        const pos = new Grow(Float32Array), col = new Grow(Uint8Array);
        let colored = false;
        const emit = (path, id, m, depth) => {
            const o = parts.get(path)?.objects.get(id);
            if (!o || depth > 16)
                return;
            for (const [cid, cpath, cm] of o.comp)
                emit(cpath ?? path, cid, mul34(cm, m), depth + 1);
            const v = o.v.a, t = o.t.a, nv = o.v.n / 3;
            for (let i = 0; i < o.t.n; i += 4) {
                if (t[i] >= nv || t[i + 1] >= nv || t[i + 2] >= nv)
                    continue;
                for (let k = 0; k < 3; k++) {
                    const j = 3 * t[i + k], x = v[j], y = v[j + 1], z = v[j + 2];
                    pos.push3((x * m[0] + y * m[3] + z * m[6] + m[9]) * scale, (x * m[1] + y * m[4] + z * m[7] + m[10]) * scale,
                        (x * m[2] + y * m[5] + z * m[8] + m[11]) * scale);
                    col.push3(...rgb(t[i + 3]));
                }
                colored ||= t[i + 3] !== BLUE;
            }
        };
        for (const [id, path, m] of main.items)
            emit(path ?? root, id, m, 0);
        if (!pos.n)
            throw new Error('This 3MF file has no objects to show');
        return { meshes: [{ pos: pos.done(), col: colored ? col.done() : null, color: colored ? 0xffffff : BLUE }], unit: 'mm' };
    }

    // ---------- OBJ: v / f (+ vertex colours), materials' Kd from the .mtl next to it; Y-up ----------

    /** The .mtl file named by an OBJ, as an item next to it (also inside archives). */
    function sibling(item, name) {
        if (!name || name.startsWith('/') || name.split(/[\\/]/).includes('..'))
            return null;
        const dir = p => p.slice(0, p.lastIndexOf('/') + 1);
        if (item.within) {
            const chain = item.within.chain;
            return { ...item, name, within: { ...item.within, chain: [...chain.slice(0, -1), dir(chain[chain.length - 1]) + name] } };
        }
        return { ...item, name, path: dir(item.path || '') + name };
    }

    async function readObj(b, item, signal) {
        const text = utf8.decode(b), mtl = new Map();
        const lib = /^[ \t]*mtllib[ \t]+(.+?)[ \t]*$/m.exec(text)?.[1], it = sibling(item, lib);
        if (it) {
            const r = await fetch(fileUrl(it), { signal }).catch(() => null);
            let cur = null;
            for (const line of (r?.ok ? await r.text() : '').split('\n')) {
                const w = line.trim().split(/\s+/);
                if (w[0] === 'newmtl')
                    cur = w.slice(1).join(' ');
                else if (w[0] === 'Kd' && cur !== null)
                    mtl.set(cur, w.slice(1, 4).map(x => Math.max(0, Math.min(255, Math.round(x * 255)))));
            }
        }
        const v = new Grow(Float32Array), vc = new Grow(Float32Array), pos = new Grow(Float32Array), col = new Grow(Uint8Array);
        let cur = null, colored = false;
        const blue = rgb(BLUE);
        for (const raw of text.split('\n')) {
            const line = raw.trim();
            if (line.startsWith('v ') || line.startsWith('v\t')) {
                const n = line.slice(2).trim().split(/\s+/).map(Number);
                v.push3(n[0], -n[2], n[1]);
                vc.push3(...(n.length >= 6 ? n.slice(3, 6).map(x => Math.min(255, x * 255)) : [-1, 0, 0]));
                colored ||= n.length >= 6;
            }
            else if (line.startsWith('f ') || line.startsWith('f\t')) {
                const nv = v.n / 3, ids = [];
                for (const tok of line.slice(2).trim().split(/\s+/)) {
                    const i = parseInt(tok), k = i < 0 ? nv + i : i - 1;
                    if (!(k >= 0 && k < nv))
                        break;
                    ids.push(k);
                }
                for (let k = 2; k < ids.length; k++)   // polygons as a fan of triangles
                    for (const i of [ids[0], ids[k - 1], ids[k]]) {
                        pos.push3(v.a[3 * i], v.a[3 * i + 1], v.a[3 * i + 2]);
                        col.push3(...(vc.a[3 * i] >= 0 ? [vc.a[3 * i], vc.a[3 * i + 1], vc.a[3 * i + 2]] : cur || blue));
                    }
            }
            else if (line.startsWith('usemtl')) {
                cur = mtl.get(line.slice(6).trim()) || null;
                colored ||= !!cur;
            }
        }
        if (!pos.n)
            throw new Error('This OBJ file has no faces to show');
        return { meshes: [{ pos: pos.done(), col: colored ? col.done() : null, color: colored ? 0xffffff : BLUE }], unit: '' };
    }

    // ---------- GLB (binary glTF 2.0): scene nodes, meshes, base colour (factor, vertex colours, texture); Y-up ----------

    const m4 = {
        mul(a, b) {   // column-major a · b
            const o = new Array(16);
            for (let c = 0; c < 4; c++)
                for (let r = 0; r < 4; r++)
                    o[c * 4 + r] = a[r] * b[c * 4] + a[4 + r] * b[c * 4 + 1] + a[8 + r] * b[c * 4 + 2] + a[12 + r] * b[c * 4 + 3];
            return o;
        },
        node(n) {
            if (n.matrix?.length === 16)
                return n.matrix;
            const [x, y, z, w] = n.rotation || [0, 0, 0, 1], [sx, sy, sz] = n.scale || [1, 1, 1], [tx, ty, tz] = n.translation || [0, 0, 0];
            return [(1 - 2 * (y * y + z * z)) * sx, 2 * (x * y + z * w) * sx, 2 * (x * z - y * w) * sx, 0,
                2 * (x * y - z * w) * sy, (1 - 2 * (x * x + z * z)) * sy, 2 * (y * z + x * w) * sy, 0,
                2 * (x * z + y * w) * sz, 2 * (y * z - x * w) * sz, (1 - 2 * (x * x + y * y)) * sz, 0, tx, ty, tz, 1];
        },
        id: [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1],
    };

    async function readGlb(b) {
        const dv = new DataView(b.buffer, b.byteOffset, b.byteLength);
        if (b.length < 20 || dv.getUint32(0, true) !== 0x46546C67 || dv.getUint32(16, true) !== 0x4E4F534A)
            throw new Error('This is not a GLB file');
        if (dv.getUint32(4, true) !== 2)
            throw new Error('Only glTF 2.0 files are supported');
        const jl = dv.getUint32(12, true), j = JSON.parse(utf8.decode(b.subarray(20, 20 + jl)));
        let bin = new Uint8Array();
        if (b.length >= 28 + jl && dv.getUint32(24 + jl, true) === 0x004E4942)
            bin = b.subarray(28 + jl, 28 + jl + dv.getUint32(20 + jl, true));
        const packed = (j.extensionsRequired || []).filter(x => /draco|meshopt/i.test(x));
        if (packed.length)
            throw new Error(`This model is compressed (${packed.join(', ')}), which the viewer can't read – please download it`);

        const bdv = new DataView(bin.buffer, bin.byteOffset, bin.byteLength);
        const SIZE = { 5120: 1, 5121: 1, 5122: 2, 5123: 2, 5125: 4, 5126: 4 }, N = { SCALAR: 1, VEC2: 2, VEC3: 3, VEC4: 4 };
        const GET = { 5120: 'getInt8', 5121: 'getUint8', 5122: 'getInt16', 5123: 'getUint16', 5125: 'getUint32', 5126: 'getFloat32' };
        const NORM = { 5120: 127, 5121: 255, 5122: 32767, 5123: 65535 };
        /** An accessor's values as [Float64-ish numbers array, components], or null. */
        const read = i => {
            const a = j.accessors?.[i], n = N[a?.type], t = a?.componentType, bv = j.bufferViews?.[a?.bufferView];
            if (!a || !n || !SIZE[t] || !bv || (bv.buffer ?? 0) !== 0)
                return null;
            const stride = bv.byteStride || n * SIZE[t], off = (bv.byteOffset || 0) + (a.byteOffset || 0), count = a.count;
            if (off + (count - 1) * stride + n * SIZE[t] > bin.length)
                return null;
            const out = new (t === 5125 ? Uint32Array : Float32Array)(count * n), get = GET[t], s = a.normalized && NORM[t];
            for (let k = 0; k < count; k++)
                for (let c = 0; c < n; c++) {
                    const x = bdv[get](off + k * stride + c * SIZE[t], true);
                    out[k * n + c] = s ? Math.max(-1, x / s) : x;
                }
            return [out, n];
        };
        const images = new Map();
        const image = async t => {
            const src = j.textures?.[t]?.source, im = j.images?.[src], bv = j.bufferViews?.[im?.bufferView];
            if (!bv || (bv.buffer ?? 0) !== 0)
                return null;   // pictures outside the file
            if (!images.has(src)) {
                const bytes = bin.subarray(bv.byteOffset || 0, (bv.byteOffset || 0) + bv.byteLength);
                images.set(src, createImageBitmap(new Blob([bytes], { type: im.mimeType || 'image/png' }), { colorSpaceConversion: 'none' }).catch(() => null));
            }
            return images.get(src);
        };

        const meshes = [];
        const prim = async (p, m) => {
            const mode = p.mode ?? 4, P = read(p.attributes?.POSITION);
            if (![4, 5, 6].includes(mode) || !P || P[1] !== 3)
                return;
            const nv = P[0].length / 3;
            let idx = p.indices !== undefined ? read(p.indices)?.[0] : Uint32Array.from({ length: nv }, (_, i) => i);
            if (!idx)
                return;
            if (mode !== 4) {   // strips and fans as a plain triangle list
                const o = [];
                for (let i = 2; i < idx.length; i++)
                    o.push(...(mode === 6 ? [idx[0], idx[i - 1], idx[i]] : i % 2 ? [idx[i - 1], idx[i - 2], idx[i]] : [idx[i - 2], idx[i - 1], idx[i]]));
                idx = o;
            }
            const mat = j.materials?.[p.material], pbr = mat?.pbrMetallicRoughness || {};
            const f = (pbr.baseColorFactor || [1, 1, 1, 1]).slice(0, 3).map(c => Math.round(255 * c ** (1 / 2.2)));
            const C = read(p.attributes?.COLOR_0), tex = pbr.baseColorTexture;
            const UV = tex && read(p.attributes?.[`TEXCOORD_${tex.texCoord || 0}`]), img = UV && await image(tex.index);
            const nt = Math.floor(idx.length / 3), pos = new Float32Array(9 * nt), uv = img ? new Float32Array(6 * nt) : null;
            const col = C && C[1] >= 3 ? new Uint8Array(9 * nt) : null;
            for (let i = 0; i < 3 * nt; i++) {
                const k = idx[i];
                if (k >= nv)
                    continue;
                const x = P[0][3 * k], y = P[0][3 * k + 1], z = P[0][3 * k + 2];
                pos[3 * i] = m[0] * x + m[4] * y + m[8] * z + m[12];   // world, then Y-up → Z-up: (x, y, z) → (x, -z, y)
                pos[3 * i + 1] = -(m[2] * x + m[6] * y + m[10] * z + m[14]);
                pos[3 * i + 2] = m[1] * x + m[5] * y + m[9] * z + m[13];
                if (uv) {
                    uv[2 * i] = UV[0][2 * k];
                    uv[2 * i + 1] = UV[0][2 * k + 1];
                }
                if (col)
                    for (let c = 0; c < 3; c++)
                        col[3 * i + c] = Math.round(255 * Math.max(0, Math.min(1, C[0][C[1] * k + c])) ** (1 / 2.2));   // linear → sRGB
            }
            meshes.push({ pos, col, uv, image: img, color: mat ? (f[0] << 16 | f[1] << 8 | f[2]) : BLUE,
                cut: mat?.alphaMode === 'MASK' ? mat.alphaCutoff ?? 0.5 : 0 });
        };

        let roots = j.scenes?.[j.scene ?? 0]?.nodes;
        if (!roots) {
            const kids = new Set((j.nodes || []).flatMap(n => n.children || []));
            roots = (j.nodes || []).map((_, i) => i).filter(i => !kids.has(i));
        }
        const stack = roots.map(n => [n, m4.id, 0]);
        while (stack.length) {
            const [n, parent, depth] = stack.pop(), node = j.nodes?.[n];
            if (!node || depth > 64)
                continue;
            const m = m4.mul(parent, m4.node(node));
            for (const c of node.children || [])
                stack.push([c, m, depth + 1]);
            for (const p of j.meshes?.[node.mesh]?.primitives || [])
                await prim(p, m);
        }
        if (!meshes.length)
            throw new Error('This GLB file has no meshes the viewer can show');
        return { meshes, unit: 'm' };
    }

    // ---------- G-code: extrusions by feature type and layer, travel moves, the slicer's stats ----------

    const FEATURES = [   // [name, colour, how slicers call it] – the first match wins
        ['Overhang wall', 0x3b6cff, /overhang/i],
        ['Outer wall', 0xff8a1f, /outer|external|wall-outer/i],
        ['Inner wall', 0xffd21f, /inner|perimeter|wall/i],
        ['Bridge', 0x4d8ac9, /bridge/i],
        ['Top surface', 0xf04848, /top/i],
        ['Bottom surface', 0x8f5bd6, /bottom/i],
        ['Solid infill', 0xa45ad8, /solid|skin/i],
        ['Support interface', 0x86e886, /support.*interface|interface/i],
        ['Support', 0x2fbf4a, /support/i],
        ['Gap fill', 0xe8e8e8, /gap/i],
        ['Ironing', 0xff9f80, /ironing/i],
        ['Skirt / brim', 0x14a38b, /skirt|brim/i],
        ['Prime tower', 0x9ca3af, /prime|wipe|tower/i],
        ['Infill', 0xb33a2f, /infill|fill/i],
        ['Other', 0x8a8f98, /./],
    ];
    const featureOf = name => FEATURES.findIndex(f => f[2].test(name));

    function readGcode(b) {
        const ext = new Grow(Float32Array, 1 << 16), ec = new Grow(Uint8Array, 1 << 16), tr = new Grow(Float32Array, 1 << 14);
        const layers = [], stats = {}, used = new Set();
        let x = 0, y = 0, z = 0, e = 0, abs = true, eAbs = true, mm = 1, type = -1, p = 0;
        const n = b.length;
        const num = () => {   // the number at p (spaces, sign, digits, fraction); p ends after it
            while (b[p] === 32 || b[p] === 9)
                p++;
            let neg = false, v = 0, c;
            if (b[p] === 45 || b[p] === 43)
                neg = b[p++] === 45;
            while ((c = b[p] - 48) >= 0 && c <= 9) {
                v = v * 10 + c;
                p++;
            }
            if (b[p] === 46) {
                p++;
                for (let f = 0.1; (c = b[p] - 48) >= 0 && c <= 9; f /= 10, p++)
                    v += c * f;
            }
            return neg ? -v : v;
        };
        const comment = s => {
            const t = /^\s*(?:TYPE|FEATURE)\s*:\s*(.+)/i.exec(s) || /^\s*feature\s+(.+)/i.exec(s);
            if (t) {
                type = featureOf(t[1].trim());
                return;
            }
            if (!/time|filament|weight/i.test(s))
                return;
            let m;
            if ((m = /estimated printing time(?: \(normal mode\))?\s*[=:]\s*(.+)/i.exec(s) || /total estimated time:\s*([^;]+)/i.exec(s) || /Build time:\s*(.+)/i.exec(s)))
                stats.time = m[1].trim();
            else if ((m = /^\s*TIME:(\d+)/.exec(s)))
                stats.time = duration(+m[1]);
            else if ((m = /filament used \[mm\]\s*=\s*([\d.]+)/i.exec(s) || /total filament length \[mm\]\s*:\s*([\d.]+)/i.exec(s) || /Filament length:\s*([\d.]+)\s*mm/i.exec(s)))
                stats.length = +m[1] / 1000;
            else if ((m = /Filament used:\s*([\d.]+)\s*m\b/i.exec(s)))
                stats.length = +m[1];
            else if ((m = /filament used \[g\]\s*=\s*([\d.]+)/i.exec(s) || /total filament weight \[g\]\s*:\s*([\d.]+)/i.exec(s) || /Plastic weight:\s*([\d.]+)\s*g/i.exec(s)))
                stats.weight = +m[1];
        };
        let extruded = 0;
        const segment = (nx, ny, nz) => {
            if (!layers.length || nz > layers[layers.length - 1].z + 1e-4)
                layers.push({ z: nz, e: ext.n / 3, t: tr.n / 3 });
            const f = type < 0 ? FEATURES.length - 1 : type;
            used.add(f);
            ext.push(x, y, z, nx, ny, nz);
            const c = rgb(FEATURES[f][1]);
            ec.push(...c, ...c);
        };

        while (p < n) {
            let end = b.indexOf(10, p);
            if (end < 0)
                end = n;
            while (b[p] === 32 || b[p] === 9)
                p++;
            if ((b[p] | 32) === 110) {   // N123 line number
                p++;
                num();
                while (b[p] === 32)
                    p++;
            }
            const c0 = b[p] | 32;
            if (b[p] === 59) {
                if (end - p < 200)
                    comment(String.fromCharCode(...b.subarray(p + 1, end)));
            }
            else if (c0 === 103 || c0 === 109) {   // G… / M…
                p++;
                const code = num(), g = c0 === 103;
                const v = {};
                while (p < end && b[p] !== 59) {
                    const k = b[p] | 32;
                    p++;
                    if (k >= 97 && k <= 122)
                        v[String.fromCharCode(k)] = num();
                }
                if (g && code >= 0 && code <= 3) {
                    const nx = v.x !== undefined ? (abs ? 0 : x) + v.x * mm : x, ny = v.y !== undefined ? (abs ? 0 : y) + v.y * mm : y;
                    const nz = v.z !== undefined ? (abs ? 0 : z) + v.z * mm : z, ne = v.e !== undefined ? (eAbs ? 0 : e) + v.e * mm : e;
                    const arc = code >= 2;
                    if (ne > e + 1e-6 && (nx !== x || ny !== y || arc)) {
                        extruded += ne - e;
                        for (const [px, py, pz] of arc ? arcPoints(x, y, z, nx, ny, nz, v, mm, code === 2) : [[nx, ny, nz]]) {
                            segment(px, py, pz);
                            [x, y, z] = [px, py, pz];
                        }
                    }
                    else if (nx !== x || ny !== y)
                        tr.push(x, y, z, nx, ny, nz);
                    [x, y, z, e] = [nx, ny, nz, ne];
                }
                else if (g && code === 90)
                    abs = eAbs = true;
                else if (g && code === 91)
                    abs = eAbs = false;
                else if (!g && code === 82)
                    eAbs = true;
                else if (!g && code === 83)
                    eAbs = false;
                else if (g && code === 92) {
                    x = v.x !== undefined ? v.x * mm : x;
                    y = v.y !== undefined ? v.y * mm : y;
                    z = v.z !== undefined ? v.z * mm : z;
                    e = v.e !== undefined ? v.e * mm : e;
                }
                else if (g && code === 20)
                    mm = 25.4;
                else if (g && code === 21)
                    mm = 1;
                else if (g && code === 28) {
                    const all = v.x === undefined && v.y === undefined && v.z === undefined;
                    x = all || v.x !== undefined ? 0 : x;
                    y = all || v.y !== undefined ? 0 : y;
                    z = all || v.z !== undefined ? 0 : z;
                }
            }
            p = end + 1;
        }
        if (!ext.n)
            throw new Error('No printing moves found in this G-code');
        stats.length ??= extruded / 1000;   // filament, if the slicer didn't say
        const typed = used.size > 1 || !used.has(FEATURES.length - 1);
        if (!typed) {   // no feature comments: colour by height instead
            const a = ext.a, lo = layers[0].z, hi = layers[layers.length - 1].z || 1;
            for (let i = 0; i < ext.n / 3; i++) {
                const t = (a[3 * i + 2] - lo) / Math.max(1e-6, hi - lo);
                ec.a.set([255 * (0.35 + 0.65 * t), 120 + 60 * t, 40], 3 * i);
            }
        }
        return {
            gcode: { ext: ext.done(), col: ec.done(), travel: tr.done(), layers, stats,
                legend: typed ? [...used].sort((a, b) => a - b).map(f => FEATURES[f]) : [] },
            unit: 'mm',
        };
    }

    /** Points along a G2 (clockwise) / G3 arc, centre from I J (relative) or radius R, every 10° or so. */
    function arcPoints(x, y, z, nx, ny, nz, v, mm, cw) {
        let cx, cy;
        if (v.i !== undefined || v.j !== undefined)
            [cx, cy] = [x + (v.i || 0) * mm, y + (v.j || 0) * mm];
        else if (v.r !== undefined) {
            const r = v.r * mm, dx = nx - x, dy = ny - y, d = Math.hypot(dx, dy);
            if (!d || Math.abs(r) < d / 2)
                return [[nx, ny, nz]];
            const h = Math.sqrt(r * r - d * d / 4) * ((cw !== r < 0) ? -1 : 1);   // the short way round unless R < 0
            [cx, cy] = [x + dx / 2 - h * dy / d, y + dy / 2 + h * dx / d];
        }
        else
            return [[nx, ny, nz]];
        const r = Math.hypot(x - cx, y - cy), a0 = Math.atan2(y - cy, x - cx), a1 = Math.atan2(ny - cy, nx - cx);
        let sw = cw ? a0 - a1 : a1 - a0;
        while (sw <= 1e-9)
            sw += 2 * Math.PI;   // same start and end: a full circle
        const k = Math.max(1, Math.min(72, Math.ceil(sw / (Math.PI / 18)))), pts = [];
        for (let i = 1; i <= k; i++) {
            const t = a0 + (cw ? -1 : 1) * sw * i / k;
            pts.push(i === k ? [nx, ny, nz] : [cx + r * Math.cos(t), cy + r * Math.sin(t), z + (nz - z) * i / k]);
        }
        return pts;
    }

    const duration = s => {
        const h = Math.floor(s / 3600), m = Math.round(s % 3600 / 60);
        return h ? `${h}h ${m}m` : `${m}m`;
    };

    // ---------- Drawing ----------

    const MESH_VS = `#version 300 es
        layout(location = 0) in vec3 aPos; layout(location = 1) in vec3 aCol; layout(location = 2) in vec2 aUV;
        uniform mat4 uMVP, uMV; out vec3 vPos, vCol; out vec2 vUV;
        void main() { vPos = (uMV * vec4(aPos, 1.)).xyz; vCol = aCol; vUV = aUV; gl_Position = uMVP * vec4(aPos, 1.); }`;
    const MESH_FS = `#version 300 es
        precision highp float;
        in vec3 vPos, vCol; in vec2 vUV; uniform vec3 uTint; uniform sampler2D uTex; uniform bool uUseTex; uniform float uCut;
        out vec4 o;
        void main() {
            vec3 c = vCol * uTint;
            if (uUseTex) { vec4 t = texture(uTex, vUV); if (t.a < uCut) discard; c *= t.rgb; }
            vec3 n = normalize(cross(dFdx(vPos), dFdy(vPos)));   // flat shading: the face's normal, from screen space
            if (n.z < 0.) n = -n;                                  // light both sides (inside-out STLs are common)
            vec3 key = normalize(vec3(-.35, .45, 1.)), fill = normalize(vec3(.6, -.1, .8));   // upper left, and right
            float d = .3 + .55 * max(dot(n, key), 0.) + .25 * max(dot(n, fill), 0.);
            float s = pow(max(dot(n, normalize(key + vec3(0, 0, 1))), 0.), 40.);
            o = vec4(c * d + .1 * s, 1.);
        }`;
    const LINE_VS = `#version 300 es
        layout(location = 0) in vec3 aPos; layout(location = 1) in vec4 aCol; uniform mat4 uMVP; uniform float uDim; out vec4 vCol;
        void main() { vCol = vec4(aCol.rgb * uDim, aCol.a); gl_Position = uMVP * vec4(aPos, 1.); }`;
    const LINE_FS = `#version 300 es
        precision mediump float; in vec4 vCol; out vec4 o; void main() { o = vCol; }`;

    function program(gl, vs, fs) {
        const p = gl.createProgram();
        for (const [type, src] of [[gl.VERTEX_SHADER, vs], [gl.FRAGMENT_SHADER, fs]]) {
            const s = gl.createShader(type);
            gl.shaderSource(s, src);
            gl.compileShader(s);
            gl.attachShader(p, s);
        }
        gl.linkProgram(p);
        if (!gl.getProgramParameter(p, gl.LINK_STATUS))
            throw new Error('WebGL: ' + gl.getProgramInfoLog(p));
        const u = {};
        for (let i = gl.getProgramParameter(p, gl.ACTIVE_UNIFORMS); i--;) {
            const name = gl.getActiveUniform(p, i).name;
            u[name] = gl.getUniformLocation(p, name);
        }
        return { p, u };
    }

    const persp = (fov, aspect, near, far) => {
        const f = 1 / Math.tan(fov / 2), nf = 1 / (near - far);
        return [f / aspect, 0, 0, 0, 0, f, 0, 0, 0, 0, (far + near) * nf, -1, 0, 0, 2 * far * near * nf, 0];
    };
    const sub = (a, b) => [a[0] - b[0], a[1] - b[1], a[2] - b[2]];
    const cross = (a, b) => [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]];
    const unit = a => {
        const l = Math.hypot(...a) || 1;
        return [a[0] / l, a[1] / l, a[2] / l];
    };
    const dot = (a, b) => a[0] * b[0] + a[1] * b[1] + a[2] * b[2];
    const fmt = v => (Math.abs(v) >= 100 ? v.toFixed(0) : Math.abs(v) >= 10 ? v.toFixed(1) : v.toFixed(2));
    const FOV = 35 * Math.PI / 180;
    const VIEWS = { fit: [-0.6, 0.45], top: [0, Math.PI / 2], front: [0, 0], side: [-Math.PI / 2, 0] };   // [yaw, pitch]

    /** Opens the file of `item` in `el` (an empty .m3d box); addInfo(title, rows): see CD.viewers render(). Returns { dispose }. */
    function open(el, item, addInfo) {
        const ctl = new AbortController(), x = ext(item.name);
        let view = null;
        el.innerHTML = '<p class="m3d-msg">Loading…</p>';
        const msg = t => {
            el.innerHTML = `<p class="m3d-msg">${esc(t)}</p>`;
        };
        (async () => {
            const b = await download(item, ctl.signal, (got, total) =>
                msg(total ? `Loading ${fmtSize(got)} of ${fmtSize(total)}…` : `Loading ${fmtSize(got)}…`));
            msg('Preparing…');
            await new Promise(r => setTimeout(r, 30));   // let that show before the (blocking) parse
            const scene = x === 'stl' ? readStl(b) : x === '3mf' ? await read3mf(b) : x === 'obj' ? await readObj(b, item, ctl.signal)
                : x === 'glb' ? await readGlb(b) : readGcode(b);
            if (!ctl.signal.aborted) {
                view = new View(el, scene);
                addInfo?.(scene.gcode ? 'Toolpaths' : 'Geometry', view.facts());
            }
        })().catch(e => {
            if (!ctl.signal.aborted)
                msg(e.name === 'RangeError' ? 'This model is too big for this device' : e.message || 'Could not show this file');
        });
        return {
            dispose() {
                ctl.abort();
                view?.dispose();
            },
        };
    }
    const ext = n => n.slice(n.lastIndexOf('.') + 1).toLowerCase();

    class View {
        constructor(el, scene) {
            this.el = el;
            this.scene = scene;
            const g = scene.gcode;
            el.innerHTML = `<canvas></canvas>
              <div class="m3d-tools">${Object.keys(VIEWS).map(k => `<button type="button" data-m="${k}">${{ fit: 'Reset', top: 'Top', front: 'Front', side: 'Side' }[k]}</button>`).join('')}</div>
              <div class="m3d-info"></div>
              ${g ? `<div class="m3d-legend">${g.legend.map(([n, c]) => `<span><i style="background:#${c.toString(16).padStart(6, '0')}"></i>${esc(n)}</span>`).join('')}</div>
              <div class="m3d-layers"><input type="range" min="1" max="${g.layers.length}" value="${g.layers.length}" aria-label="Layer">
              <label><input type="checkbox"> Travel</label></div>` : ''}`;
            this.canvas = el.querySelector('canvas');
            const gl = this.gl = this.canvas.getContext('webgl2', { antialias: true });
            if (!gl)
                throw new Error('This browser cannot show 3D models (WebGL 2 is off or not supported)');
            this.mesh = program(gl, MESH_VS, MESH_FS);
            this.line = program(gl, LINE_VS, LINE_FS);
            this.textures = new Map();
            this.parts = g ? this.gcodeParts(g) : scene.meshes.map(m => this.meshPart(m));
            this.bounds();
            this.grid = this.makeGrid();
            this.layer = g ? g.layers.length : 0;
            this.travel = false;
            this.info();
            this.setView('fit');
            this.listen();
            this.ro = new ResizeObserver(() => this.redraw());
            this.ro.observe(el);
            this.canvas.addEventListener('webglcontextlost', e => {
                e.preventDefault();
                if (!this.gone)
                    el.innerHTML = '<p class="m3d-msg">The graphics card was reset – please open the file again</p>';
            });
        }

        buffer(loc, data, size, type = this.gl.FLOAT, norm = false) {
            const gl = this.gl, b = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, b);
            gl.bufferData(gl.ARRAY_BUFFER, data, gl.STATIC_DRAW);
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, size, type, norm, 0, 0);
        }

        meshPart(m) {
            const gl = this.gl, vao = gl.createVertexArray();
            gl.bindVertexArray(vao);
            this.buffer(0, m.pos, 3);
            if (m.col)
                this.buffer(1, m.col, 3, gl.UNSIGNED_BYTE, true);
            if (m.uv && m.image)
                this.buffer(2, m.uv, 2);
            gl.bindVertexArray(null);
            let tex = null;
            if (m.uv && m.image) {
                tex = this.textures.get(m.image);
                if (!tex) {
                    tex = gl.createTexture();
                    gl.bindTexture(gl.TEXTURE_2D, tex);
                    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
                    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, m.image);
                    gl.generateMipmap(gl.TEXTURE_2D);
                    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR);
                    this.textures.set(m.image, tex);
                }
            }
            return { vao, n: m.pos.length / 3, pos: m.pos, col: !!m.col, tint: rgb(m.color).map(c => c / 255), tex, cut: m.cut || 0 };
        }

        gcodeParts(g) {
            const gl = this.gl, mk = (pos, col) => {
                const vao = gl.createVertexArray();
                gl.bindVertexArray(vao);
                this.buffer(0, pos, 3);
                if (col)
                    this.buffer(1, col, 3, gl.UNSIGNED_BYTE, true);
                gl.bindVertexArray(null);
                return vao;
            };
            return { ext: mk(g.ext, g.col), travel: g.travel.length ? mk(g.travel, null) : null, pos: g.ext };
        }

        /** Bounding box and radius of everything shown. */
        bounds() {
            const lo = [Infinity, Infinity, Infinity], hi = [-Infinity, -Infinity, -Infinity];
            for (const pos of this.scene.gcode ? [this.parts.pos] : this.parts.map(p => p.pos))
                for (let i = 0; i < pos.length; i += 3)
                    for (let k = 0; k < 3; k++) {
                        const v = pos[i + k];
                        if (v < lo[k])
                            lo[k] = v;
                        if (v > hi[k])
                            hi[k] = v;
                    }
            if (!(lo[0] <= hi[0]))
                throw new Error('This file has nothing to show');
            this.lo = lo;
            this.hi = hi;
            this.center = lo.map((v, k) => (v + hi[k]) / 2);
            this.radius = Math.max(1e-6, Math.hypot(hi[0] - lo[0], hi[1] - lo[1], hi[2] - lo[2]) / 2);
        }

        /** A floor grid under the model (or the print bed at Z 0 for G-code), every 1 / 2 / 5 × 10ⁿ units. */
        makeGrid() {
            const gl = this.gl, size = Math.max(this.hi[0] - this.lo[0], this.hi[1] - this.lo[1]) * 1.5 || 1;
            const p10 = 10 ** Math.floor(Math.log10(size / 10)), step = [1, 2, 5, 10].map(k => k * p10).find(s => size / s <= 20);
            const half = Math.ceil(size / 2 / step) * step, z = this.scene.gcode ? 0 : this.lo[2] - this.radius * 1e-3;
            const cx = Math.round(this.center[0] / step) * step, cy = Math.round(this.center[1] / step) * step;
            const pos = [], col = [];
            for (let v = -half, i = Math.round(-half / step); v <= half + 1e-9; v += step, i++) {
                const a = i % 5 ? 28 : 60;
                pos.push(cx + v, cy - half, z, cx + v, cy + half, z, cx - half, cy + v, z, cx + half, cy + v, z);
                col.push(...Array(4).fill([255, 255, 255, a]).flat());
            }
            const vao = gl.createVertexArray();
            gl.bindVertexArray(vao);
            this.buffer(0, new Float32Array(pos), 3);
            this.buffer(1, new Uint8Array(col), 4, gl.UNSIGNED_BYTE, true);
            gl.bindVertexArray(null);
            return { vao, n: pos.length / 3 };
        }

        /** Measured from what was drawn, for the info panel. */
        facts() {
            const s = this.scene, g = s.gcode, d = this.hi.map((v, k) => v - this.lo[k]), u = s.unit ? ` ${s.unit}` : '';
            if (g)
                return { Size: `${fmt(d[0])} × ${fmt(d[1])} × ${fmt(this.hi[2])} mm`, Layers: g.layers.length };
            return { Size: d.map(fmt).join(' × ') + u, Triangles: this.parts.reduce((n, p) => n + p.n / 3, 0) };
        }

        info() {
            const s = this.scene, g = s.gcode, d = this.hi.map((v, k) => v - this.lo[k]), u = s.unit ? ` ${s.unit}` : '';
            let html;
            if (g) {
                const L = g.layers[this.layer - 1], st = g.stats;
                html = `<b>Layer ${this.layer} of ${g.layers.length}</b> · Z ${fmt(L.z)} mm${s.note ? ` · ${esc(s.note)}` : ''}<br>`
                    + [st.time && `Print time ${esc(st.time)}`, st.length && `Filament ${fmt(st.length)} m`, st.weight && `${fmt(st.weight)} g`,
                        `${fmt(d[0])} × ${fmt(d[1])} × ${fmt(this.hi[2])} mm`].filter(Boolean).join(' · ');
            }
            else {
                const tris = this.parts.reduce((n, p) => n + p.n / 3, 0);
                html = `<b>${d.map(fmt).join(' × ')}${u}</b> · ${tris.toLocaleString()} triangles`;
            }
            this.el.querySelector('.m3d-info').innerHTML = `${html}<small>Drag to turn · scroll or pinch to zoom · right-drag or two fingers to move</small>`;
        }

        setView(k) {
            [this.yaw, this.pitch] = VIEWS[k];
            this.target = [...this.center];
            // just far enough back for every corner of the box to be in view, with a margin
            this.dist = 0;
            const [, right, up, back] = this.axes(), ty = Math.tan(FOV / 2) * 0.85;
            const tx = ty * (this.canvas.clientWidth / Math.max(1, this.canvas.clientHeight) || 1);
            for (let i = 0; i < 8; i++) {
                const c = [i & 1 ? this.hi[0] : this.lo[0], i & 2 ? this.hi[1] : this.lo[1], i & 4 ? this.hi[2] : this.lo[2]];
                const d = sub(c, this.center);
                this.dist = Math.max(this.dist, dot(d, back) + Math.abs(dot(d, right)) / tx, dot(d, back) + Math.abs(dot(d, up)) / ty);
            }
            this.dist = Math.max(this.dist, this.radius * 0.1);
            this.redraw();
        }

        listen() {
            const c = this.canvas, pts = new Map();
            let last = null;   // previous [x, y, distance] of the gesture
            const gesture = () => {
                const p = [...pts.values()];
                return p.length > 1 ? [(p[0].x + p[1].x) / 2, (p[0].y + p[1].y) / 2, Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y)] : [p[0].x, p[0].y, 0];
            };
            c.addEventListener('pointerdown', e => {
                c.setPointerCapture(e.pointerId);
                pts.set(e.pointerId, { x: e.clientX, y: e.clientY, pan: e.button === 2 || e.button === 1 || e.shiftKey || e.ctrlKey || e.metaKey });
                last = gesture();
            });
            c.addEventListener('pointermove', e => {
                const p = pts.get(e.pointerId);
                if (!p)
                    return;
                [p.x, p.y] = [e.clientX, e.clientY];
                const now = gesture(), dx = now[0] - last[0], dy = now[1] - last[1];
                if (pts.size > 1) {
                    this.pan(dx, dy);
                    if (last[2] && now[2])
                        this.zoom(last[2] / now[2]);
                }
                else if (p.pan)
                    this.pan(dx, dy);
                else {
                    this.yaw += dx * 0.008;   // as if dragging the model itself
                    this.pitch = Math.max(-1.5705, Math.min(1.5705, this.pitch + dy * 0.008));   // straight down / up at most
                    this.redraw();
                }
                last = now;
            });
            const up = e => {
                pts.delete(e.pointerId);
                if (pts.size)
                    last = gesture();
            };
            c.addEventListener('pointerup', up);
            c.addEventListener('pointercancel', up);
            c.addEventListener('wheel', e => {
                e.preventDefault();
                const r = c.getBoundingClientRect();
                this.zoom(Math.exp(e.deltaY * (e.deltaMode ? 33 : 1) * 0.0015), (e.clientX - r.left) / r.width * 2 - 1, 1 - (e.clientY - r.top) / r.height * 2);
            }, { passive: false });
            c.addEventListener('dblclick', () => this.setView('fit'));
            c.addEventListener('contextmenu', e => e.preventDefault());
            this.el.querySelector('.m3d-tools').addEventListener('click', e => {
                const b = e.target.closest('[data-m]');
                if (b)
                    this.setView(b.dataset.m);
            });
            const range = this.el.querySelector('.m3d-layers input[type=range]');
            range?.addEventListener('input', () => {
                this.layer = +range.value;
                this.info();
                this.redraw();
            });
            this.el.querySelector('.m3d-layers input[type=checkbox]')?.addEventListener('change', e => {
                this.travel = e.target.checked;
                this.redraw();
            });
        }

        /** Camera axes: [eye, right, up] for the current yaw / pitch / distance. */
        axes() {
            const cp = Math.cos(this.pitch), back = [Math.sin(-this.yaw) * cp, -Math.cos(-this.yaw) * cp, Math.sin(this.pitch)];
            const eye = this.target.map((t, k) => t + back[k] * this.dist);
            const right = unit(cross([0, 0, 1], back)), up = cross(back, right);
            return [eye, right, up, back];
        }

        pan(dx, dy) {
            const [, right, up] = this.axes(), k = 2 * this.dist * Math.tan(FOV / 2) / Math.max(1, this.canvas.clientHeight);
            this.target = this.target.map((t, i) => t - right[i] * dx * k + up[i] * dy * k);
            this.redraw();
        }

        /** Zooms by factor f (< 1: closer), keeping the point under the cursor (nx, ny in -1…1) in place. */
        zoom(f, nx = 0, ny = 0) {
            const nd = Math.max(this.radius * 0.02, Math.min(this.radius * 40, this.dist * f));
            f = nd / this.dist;
            const [, right, up] = this.axes(), h = this.dist * Math.tan(FOV / 2), w = h * this.canvas.clientWidth / Math.max(1, this.canvas.clientHeight);
            this.target = this.target.map((t, i) => t + (right[i] * nx * w + up[i] * ny * h) * (1 - f));
            this.dist = nd;
            this.redraw();
        }

        redraw() {
            if (!this.frame && !this.gone)
                this.frame = requestAnimationFrame(() => {
                    this.frame = 0;
                    this.draw();
                });
        }

        draw() {
            const gl = this.gl, c = this.canvas, dpr = Math.min(2, devicePixelRatio || 1);
            const w = Math.round(c.clientWidth * dpr), h = Math.round(c.clientHeight * dpr);
            if (!w || !h || gl.isContextLost())
                return;
            if (c.width !== w || c.height !== h)
                [c.width, c.height] = [w, h];
            gl.viewport(0, 0, w, h);
            gl.clearColor(0.075, 0.084, 0.1, 1);
            gl.clear(gl.COLOR_BUFFER_BIT | gl.DEPTH_BUFFER_BIT);
            gl.enable(gl.DEPTH_TEST);

            const [eye, right, up, back] = this.axes();
            const view = [right[0], up[0], back[0], 0, right[1], up[1], back[1], 0, right[2], up[2], back[2], 0,
                -dot(right, eye), -dot(up, eye), -dot(back, eye), 1];
            const near = Math.max(this.dist - this.radius * 2, this.dist * 0.01, this.radius * 1e-4);
            const mvp = m4.mul(persp(FOV, w / h, near, this.dist + this.radius * 4), view);

            gl.useProgram(this.line.p);
            gl.uniformMatrix4fv(this.line.u.uMVP, false, mvp);
            gl.uniform1f(this.line.u.uDim, 1);
            gl.enable(gl.BLEND);
            gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
            gl.depthMask(false);
            gl.bindVertexArray(this.grid.vao);
            gl.drawArrays(gl.LINES, 0, this.grid.n);
            gl.depthMask(true);
            gl.disable(gl.BLEND);

            const g = this.scene.gcode;
            if (g)
                this.drawGcode(g);
            else {
                gl.useProgram(this.mesh.p);
                const u = this.mesh.u;
                gl.uniformMatrix4fv(u.uMVP, false, mvp);
                gl.uniformMatrix4fv(u.uMV, false, view);
                for (const p of this.parts) {
                    gl.bindVertexArray(p.vao);
                    if (!p.col)
                        gl.vertexAttrib3f(1, 1, 1, 1);
                    gl.uniform3fv(u.uTint, p.tint);
                    gl.uniform1i(u.uUseTex, p.tex ? 1 : 0);
                    gl.uniform1f(u.uCut, p.cut);
                    if (p.tex)
                        gl.bindTexture(gl.TEXTURE_2D, p.tex);
                    gl.drawArrays(gl.TRIANGLES, 0, p.n);
                }
            }
            gl.bindVertexArray(null);
        }

        /** Layers up to the slider; the current one bright and the ones below dimmed while scrubbing. */
        drawGcode(g) {
            const gl = this.gl, L = g.layers, i = this.layer - 1, top = i === L.length - 1, P = this.parts;
            const start = L[i].e, end = L[i + 1]?.e ?? g.ext.length / 3;
            gl.bindVertexArray(P.ext);
            gl.uniform1f(this.line.u.uDim, top ? 1 : 0.45);
            gl.drawArrays(gl.LINES, 0, top ? end : start);
            if (!top) {
                gl.uniform1f(this.line.u.uDim, 1);
                gl.drawArrays(gl.LINES, start, end - start);
            }
            if (this.travel && P.travel) {
                const t0 = L[i].t, t1 = L[i + 1]?.t ?? g.travel.length / 3;
                gl.bindVertexArray(P.travel);
                gl.vertexAttrib4f(1, 0.35, 0.6, 1, 0.8);
                gl.uniform1f(this.line.u.uDim, 1);
                gl.drawArrays(gl.LINES, t0, t1 - t0);
            }
        }

        dispose() {
            this.gone = true;
            cancelAnimationFrame(this.frame);
            this.ro.disconnect();
            this.gl.getExtension('WEBGL_lose_context')?.loseContext();   // browsers allow only a few live contexts
        }
    }

    CD.model3d = { open };
})();
