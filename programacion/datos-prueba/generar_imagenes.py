#!/usr/bin/env python3
"""
Generador de afiches y salas de demostración para IndieCinema.
Crea imágenes de licencia libre (CC0 / Dominio Público) con obras y salas
históricas y ficticias, sin datos personales reales.
"""

import os
import math
from PIL import Image, ImageDraw, ImageFont

base_dir = os.path.dirname(os.path.abspath(__file__))
afiches_dir = os.path.join(base_dir, 'afiches')
salas_dir = os.path.join(base_dir, 'salas')
os.makedirs(afiches_dir, exist_ok=True)
os.makedirs(salas_dir, exist_ok=True)

font_bold = '/usr/share/fonts/TTF/OpenSans-CondensedExtraBold.ttf'
font_serif = '/usr/share/fonts/noto/NotoSerif-Regular.ttf'
font_sans = '/usr/share/fonts/Adwaita/AdwaitaSans-Regular.ttf'

def get_font(path, size):
    # Sin la fuente, Pillow usaría la suya por defecto y las imágenes saldrían distintas a las
    # versionadas: mejor frenar y decir cuál falta.
    try:
        return ImageFont.truetype(path, size)
    except OSError as error:
        raise SystemExit(f'Falta la fuente {path}; ver el README de datos-prueba.') from error

def draw_star(draw, cx, cy, r_outer, r_inner, fill):
    pts = []
    for i in range(10):
        r = r_outer if i % 2 == 0 else r_inner
        ang = -math.pi / 2 + i * math.pi / 5
        pts.append((cx + r * math.cos(ang), cy + r * math.sin(ang)))
    draw.polygon(pts, fill=fill)

def make_poster(filename, title, subtitle, director, year, duration, theme_colors, art_type):
    bg_col, pri_col, acc_col, txt_col = theme_colors
    w, h = 600, 900
    img = Image.new('RGB', (w, h), bg_col)
    draw = ImageDraw.Draw(img)

    # Fondo degradado sutil
    for y in range(h):
        factor = y / h
        r = int(bg_col[0] * (1 - factor * 0.35) + pri_col[0] * factor * 0.15)
        g = int(bg_col[1] * (1 - factor * 0.35) + pri_col[1] * factor * 0.15)
        b = int(bg_col[2] * (1 - factor * 0.35) + pri_col[2] * factor * 0.15)
        draw.line([(0, y), (w, y)], fill=(r, g, b))

    # Guardas decorativas
    draw.rectangle([(24, 24), (w - 24, h - 24)], outline=acc_col, width=2)
    draw.rectangle([(32, 32), (w - 32, h - 32)], outline=pri_col, width=1)

    # Esquinas
    c_len = 20
    for cx_c, cy_c in [(32, 32), (w-32, 32), (32, h-32), (w-32, h-32)]:
        dx = c_len if cx_c == 32 else -c_len
        dy = c_len if cy_c == 32 else -c_len
        draw.line([(cx_c, cy_c), (cx_c + dx, cy_c)], fill=acc_col, width=3)
        draw.line([(cx_c, cy_c), (cx_c, cy_c + dy)], fill=acc_col, width=3)

    # Cabecera superior
    f_top = get_font(font_sans, 14)
    draw.text((w / 2, 60), 'INDIE CINEMA · CLÁSICOS EN DOMINIO PÚBLICO', font=f_top, fill=acc_col, anchor='mm')
    draw.line([(140, 75), (460, 75)], fill=acc_col, width=1)

    # Área artística central
    art_y1, art_y2 = 110, 610
    cx, cy = w / 2, (art_y1 + art_y2) / 2

    if art_type == 'metropolis':
        for radius in range(50, 220, 25):
            draw.arc([cx - radius, cy - radius - 30, cx + radius, cy + radius - 30], 0, 360, fill=acc_col, width=1)
        for x_step in [-140, -90, -40, 0, 40, 90, 140]:
            h_spike = 160 - abs(x_step)
            draw.rectangle([cx + x_step - 18, cy + 40 - h_spike, cx + x_step + 18, cy + 180], fill=pri_col, outline=acc_col)
        draw.ellipse([cx - 45, cy - 85, cx + 45, cy + 5], fill=bg_col, outline=acc_col, width=3)
        draw.polygon([(cx - 25, cy - 40), (cx + 25, cy - 40), (cx, cy + 10)], fill=acc_col)

    elif art_type == 'nosferatu':
        draw.ellipse([cx - 110, cy - 130, cx + 110, cy + 90], fill=(220, 215, 180))
        draw.arc([cx - 150, cy - 180, cx + 150, cy + 120], 180, 360, fill=acc_col, width=3)
        draw.line([(cx - 150, cy - 30), (cx - 150, cy + 180)], fill=acc_col, width=3)
        draw.line([(cx + 150, cy - 30), (cx + 150, cy + 180)], fill=acc_col, width=3)
        draw.polygon([(cx - 40, cy + 180), (cx + 40, cy + 180), (cx + 25, cy - 20), (cx, cy - 80), (cx - 25, cy - 20)], fill=(12, 14, 18))
        draw.line([(cx - 30, cy + 30), (cx - 75, cy - 10)], fill=(12, 14, 18), width=5)
        draw.line([(cx + 30, cy + 30), (cx + 75, cy - 10)], fill=(12, 14, 18), width=5)

    elif art_type == 'luna':
        draw.ellipse([cx - 130, cy - 130, cx + 130, cy + 130], fill=(235, 220, 150), outline=acc_col, width=3)
        for mx, my, mr in [(cx - 50, cy - 60, 24), (cx + 60, cy - 40, 30), (cx - 40, cy + 50, 35), (cx + 45, cy + 45, 20)]:
            draw.ellipse([mx - mr, my - mr, mx + mr, my + mr], fill=(205, 190, 125))
        draw.polygon([(cx + 20, cy - 15), (cx + 130, cy + 40), (cx + 110, cy + 60), (cx + 5, cy + 5)], fill=(180, 60, 40), outline=acc_col)
        for sx, sy in [(100, 170), (140, 280), (490, 180), (460, 320), (120, 480), (480, 490)]:
            draw_star(draw, sx, sy, 14, 6, acc_col)

    elif art_type == 'caligari':
        pts = [(cx - 160, cy + 170), (cx - 110, cy - 80), (cx - 60, cy + 30), (cx, cy - 150), (cx + 80, cy + 20), (cx + 130, cy - 60), (cx + 170, cy + 170)]
        draw.polygon(pts, fill=pri_col, outline=acc_col, width=2)
        draw.polygon([(cx - 40, cy - 30), (cx + 10, cy - 70), (cx + 45, cy - 10), (cx, cy + 30)], fill=(240, 220, 130), outline=(20, 20, 20), width=2)
        draw.ellipse([cx - 15, cy + 50, cx + 15, cy + 80], fill=(10, 10, 12))
        draw.polygon([(cx - 25, cy + 180), (cx + 25, cy + 180), (cx + 15, cy + 80), (cx - 15, cy + 80)], fill=(10, 10, 12))

    elif art_type == 'general':
        draw.polygon([(cx - 130, cy + 160), (cx + 130, cy + 160), (cx, cy + 40)], fill=pri_col, outline=acc_col, width=3)
        for wx in [-90, 0, 90]:
            draw.ellipse([cx + wx - 35, cy + 80, cx + wx + 35, cy + 150], outline=acc_col, width=3)
            draw.line([(cx + wx - 35, cy + 115), (cx + wx + 35, cy + 115)], fill=acc_col, width=2)
        draw.rectangle([cx - 30, cy - 70, cx + 30, cy + 40], fill=pri_col, outline=acc_col)
        draw.ellipse([cx - 45, cy - 90, cx + 45, cy - 60], fill=acc_col)
        draw.ellipse([cx - 70, cy - 145, cx + 70, cy - 125], fill=(20, 20, 20))
        draw.rectangle([cx - 45, cy - 170, cx + 45, cy - 135], fill=(20, 20, 20))

    elif art_type == 'potemkin':
        draw.polygon([(80, cy + 170), (cx + 170, cy - 80), (cx + 170, cy + 170)], fill=(190, 35, 30))
        draw.line([(cx - 100, cy + 20), (cx + 140, cy - 100)], fill=(240, 240, 240), width=10)
        draw.line([(cx - 80, cy + 60), (cx + 160, cy - 60)], fill=(240, 240, 240), width=10)
        draw.ellipse([cx - 80, cy - 130, cx + 80, cy + 30], outline=(240, 240, 240), width=8)

    elif art_type == 'living_dead':
        draw.rectangle([cx - 120, cy - 30, cx + 120, cy + 170], fill=pri_col, outline=acc_col)
        draw.polygon([(cx - 140, cy - 30), (cx + 140, cy - 30), (cx, cy - 140)], fill=(15, 15, 20), outline=acc_col)
        draw.rectangle([cx - 30, cy + 10, cx + 30, cy + 80], fill=(235, 190, 50))
        for hx, hy in [(cx - 90, cy + 140), (cx - 40, cy + 160), (cx + 40, cy + 150), (cx + 90, cy + 130)]:
            draw.line([(hx, hy + 50), (hx, hy)], fill=(200, 40, 40), width=6)
            draw.line([(hx, hy), (hx - 12, hy - 20)], fill=(200, 40, 40), width=3)
            draw.line([(hx, hy), (hx, hy - 24)], fill=(200, 40, 40), width=3)
            draw.line([(hx, hy), (hx + 12, hy - 20)], fill=(200, 40, 40), width=3)

    elif art_type == 'sherlock':
        draw.ellipse([cx - 80, cy - 100, cx + 80, cy + 60], outline=acc_col, width=8)
        draw.line([(cx + 56, cy + 42), (cx + 130, cy + 116)], fill=acc_col, width=16)
        draw.ellipse([cx - 130, cy - 120, cx - 70, cy - 60], fill=pri_col, outline=acc_col, width=3)
        draw.ellipse([cx - 150, cy - 40, cx - 90, cy + 20], fill=pri_col, outline=acc_col, width=3)
        draw.polygon([(cx - 70, cy - 90), (cx + 150, cy - 140), (cx + 150, cy + 150), (cx - 70, cy + 10)], fill=acc_col)

    elif art_type == 'camara':
        for rad in [140, 115, 90, 65, 40]:
            draw.ellipse([cx - rad, cy - rad, cx + rad, cy + rad], outline=acc_col, width=2)
        draw.ellipse([cx - 35, cy - 35, cx + 35, cy + 35], fill=acc_col)
        draw.ellipse([cx - 18, cy - 18, cx + 18, cy + 18], fill=bg_col)
        draw.line([(cx, cy + 130), (cx - 120, cy + 180)], fill=acc_col, width=4)
        draw.line([(cx, cy + 130), (cx, cy + 180)], fill=acc_col, width=4)
        draw.line([(cx, cy + 130), (cx + 120, cy + 180)], fill=acc_col, width=4)

    elif art_type == 'fantasma':
        draw.line([(cx, cy - 150), (cx, cy - 90)], fill=acc_col, width=3)
        draw.polygon([(cx - 70, cy - 90), (cx + 70, cy - 90), (cx, cy - 40)], outline=acc_col, width=2)
        draw.chord([cx - 70, cy - 30, cx + 70, cy + 130], 270, 90, fill=(240, 240, 235), outline=acc_col, width=3)
        draw.ellipse([cx + 15, cy + 10, cx + 45, cy + 45], fill=bg_col)

    elif art_type == 'imposible':
        draw.ellipse([cx + 20, cy - 140, cx + 160, cy], fill=(245, 175, 40), outline=acc_col, width=3)
        for a in range(0, 360, 30):
            rad = math.radians(a)
            sx = cx + 90 + math.cos(rad) * 85
            sy = cy - 70 + math.sin(rad) * 85
            draw.line([(cx + 90, cy - 70), (sx, sy)], fill=acc_col, width=2)
        draw.rectangle([cx - 140, cy + 30, cx - 20, cy + 100], fill=pri_col, outline=acc_col, width=2)
        draw.ellipse([cx - 125, cy + 90, cx - 95, cy + 120], fill=acc_col)
        draw.ellipse([cx - 65, cy + 90, cx - 35, cy + 120], fill=acc_col)

    elif art_type == 'pibe':
        draw.line([(cx + 80, cy - 140), (cx + 80, cy + 170)], fill=acc_col, width=4)
        draw.polygon([(cx + 60, cy - 140), (cx + 100, cy - 140), (cx + 90, cy - 100), (cx + 70, cy - 100)], fill=(255, 230, 130), outline=acc_col)
        draw.ellipse([cx - 110, cy - 30, cx + 20, cy - 10], fill=(20, 20, 24))
        draw.chord([cx - 95, cy - 70, cx + 5, cy - 20], 180, 360, fill=(20, 20, 24))
        draw.arc([cx - 60, cy, cx - 10, cy + 60], 180, 360, fill=acc_col, width=5)
        draw.line([(cx - 60, cy + 30), (cx - 60, cy + 150)], fill=acc_col, width=5)

    # Texto inferior
    draw.line([(60, 640), (w - 60, 640)], fill=acc_col, width=2)

    # Título
    f_title = get_font(font_bold, 44 if len(title) <= 15 else 34)
    draw.text((w / 2, 690), title.upper(), font=f_title, fill=txt_col, anchor='mm')

    # Director
    f_sub = get_font(font_serif, 21)
    draw.text((w / 2, 745), f'DIRIGIDA POR {director.upper()}', font=f_sub, fill=acc_col, anchor='mm')

    # Metadatos
    f_meta = get_font(font_sans, 16)
    meta_text = f'{year}  ·  {duration} MIN  ·  {subtitle.upper()}'
    draw.text((w / 2, 795), meta_text, font=f_meta, fill=txt_col, anchor='mm')

    # Licencia
    f_foot = get_font(font_sans, 13)
    draw.text((w / 2, 850), 'COLECCIÓN HISTÓRICA INDIE CINEMA  ·  LICENCIA LIBRE', font=f_foot, fill=pri_col, anchor='mm')

    out_path = os.path.join(afiches_dir, filename)
    img.save(out_path, 'JPEG', quality=88, optimize=True)
    print(f'Guardado afiche {filename} ({title}): {os.path.getsize(out_path)} bytes')

def make_sala(filename, name, locality, desc, style_type):
    w, h = 800, 500
    img = Image.new('RGB', (w, h), (18, 20, 26))
    draw = ImageDraw.Draw(img)

    for y in range(h):
        fac = y / h
        r = int(16 * (1 - fac) + 32 * fac)
        g = int(18 * (1 - fac) + 24 * fac)
        b = int(26 * (1 - fac) + 34 * fac)
        draw.line([(0, y), (w, y)], fill=(r, g, b))

    cx, cy = w / 2, 210

    # Pantalla de cine
    screen_w, screen_h = 440, 210
    draw.rectangle([cx - screen_w/2 - 8, cy - screen_h/2 - 8, cx + screen_w/2 + 8, cy + screen_h/2 + 8], fill=(10, 10, 12), outline=(180, 150, 80), width=2)
    draw.rectangle([cx - screen_w/2, cy - screen_h/2, cx + screen_w/2, cy + screen_h/2], fill=(235, 240, 245))
    draw.polygon([(cx - screen_w/2, cy + screen_h/2), (cx + screen_w/2, cy + screen_h/2), (w, h - 80), (0, h - 80)], fill=(45, 42, 50))

    overlay = Image.new('RGBA', (w, h), (0, 0, 0, 0))
    d_over = ImageDraw.Draw(overlay)
    d_over.polygon([(cx, 0), (cx - screen_w/2, cy - screen_h/2), (cx + screen_w/2, cy - screen_h/2)], fill=(255, 250, 220, 45))
    img.paste(Image.alpha_composite(img.convert('RGBA'), overlay).convert('RGB'))
    draw = ImageDraw.Draw(img)

    if style_type == 'teatro':
        draw.polygon([(0, 0), (120, 0), (70, cy + 90), (0, cy + 90)], fill=(130, 25, 25))
        draw.polygon([(w, 0), (w - 120, 0), (w - 70, cy + 90), (w, cy + 90)], fill=(130, 25, 25))
    elif style_type == 'trocha':
        for x in range(30, w, 80):
            draw.line([(x, 0), (x, 100)], fill=(110, 60, 45), width=3)
        draw.line([(0, 50), (w, 50)], fill=(70, 75, 80), width=4)
    elif style_type == 'eureka':
        for sx in [80, w - 80]:
            draw.polygon([(sx - 15, cy - 30), (sx + 15, cy - 30), (sx, cy)], fill=(225, 175, 60))
    elif style_type == 'areco':
        draw.rectangle([0, 0, w, 24], fill=(70, 45, 25))
        draw.rectangle([40, 0, 70, cy], fill=(60, 40, 20))
        draw.rectangle([w - 70, 0, w - 40, cy], fill=(60, 40, 20))

    for row_y, seat_w in [(330, 45), (380, 55), (435, 65)]:
        y_pos = row_y
        seat_color = (135, 30, 30) if style_type in ['teatro', 'trocha'] else (35, 55, 85)
        for sx in range(40, w - 40, seat_w + 12):
            draw.rounded_rectangle([sx, y_pos, sx + seat_w, y_pos + 45], radius=6, fill=seat_color, outline=(15, 15, 20), width=2)
            draw.rectangle([sx + 6, y_pos + 35, sx + seat_w - 6, y_pos + 45], fill=(20, 20, 25))

    draw.rectangle([(0, h - 80), (w, h)], fill=(14, 16, 22))
    draw.line([(0, h - 80), (w, h - 80)], fill=(190, 160, 80), width=2)

    f_name = get_font(font_bold, 24)
    f_loc = get_font(font_sans, 15)

    draw.text((36, h - 55), name.upper(), font=f_name, fill=(245, 240, 230), anchor='ls')
    draw.text((36, h - 22), f'{locality.upper()}  ·  SALA HABILITADA', font=f_loc, fill=(195, 165, 85), anchor='ls')

    badge_w, badge_h = 160, 34
    draw.rounded_rectangle([w - badge_w - 30, h - 58, w - 30, h - 24], radius=4, outline=(190, 160, 80), width=1)
    f_badge = get_font(font_sans, 13)
    draw.text((w - 30 - badge_w/2, h - 41), 'ESPACIO INDIE', font=f_badge, fill=(240, 235, 220), anchor='mm')

    out_path = os.path.join(salas_dir, filename)
    img.save(out_path, 'JPEG', quality=88, optimize=True)
    print(f'Guardada sala {filename} ({name} - {locality}): {os.path.getsize(out_path)} bytes')

posters = [
    ('af1c0001000000000000000000000001.jpg', 'Metrópolis', 'Ciencia Ficción / Drama', 'Fritz Lang', 1927, 153, ((14, 18, 28), (40, 55, 80), (212, 175, 55), (245, 240, 225)), 'metropolis'),
    ('af1c0002000000000000000000000002.jpg', 'Nosferatu', 'Terror / Suspenso', 'F. W. Murnau', 1922, 94, ((12, 18, 20), (30, 48, 52), (180, 50, 45), (230, 235, 235)), 'nosferatu'),
    ('af1c0003000000000000000000000003.jpg', 'Viaje a la Luna', 'Ciencia Ficción / Fantasía', 'Georges Méliès', 1902, 13, ((18, 20, 36), (45, 52, 90), (225, 185, 75), (248, 245, 230)), 'luna'),
    ('af1c0004000000000000000000000004.jpg', 'Dr. Caligari', 'Terror / Suspenso', 'Robert Wiene', 1920, 77, ((24, 20, 18), (60, 45, 38), (210, 160, 60), (240, 230, 215)), 'caligari'),
    ('af1c0005000000000000000000000005.jpg', 'La General', 'Comedia / Aventura', 'Buster Keaton', 1926, 79, ((26, 26, 28), (65, 65, 70), (195, 135, 70), (245, 242, 235)), 'general'),
    ('af1c0006000000000000000000000006.jpg', 'El Acorazado Potemkin', 'Drama Histórico', 'Serguéi Eisenstein', 1925, 75, ((24, 14, 14), (80, 25, 25), (215, 45, 40), (250, 245, 240)), 'potemkin'),
    ('af1c0007000000000000000000000007.jpg', 'Muertos Vivientes', 'Terror / Culto', 'George A. Romero', 1968, 96, ((16, 16, 18), (45, 45, 50), (195, 35, 35), (235, 235, 240)), 'living_dead'),
    ('af1c0008000000000000000000000008.jpg', 'Sherlock Jr.', 'Comedia / Misterio', 'Buster Keaton', 1924, 45, ((28, 22, 18), (75, 50, 35), (210, 160, 80), (245, 240, 225)), 'sherlock'),
    ('af1c0009000000000000000000000009.jpg', 'El Hombre de la Cámara', 'Vanguardia / Documental', 'Dziga Vértov', 1929, 68, ((16, 24, 28), (35, 65, 75), (220, 165, 50), (235, 245, 245)), 'camara'),
    ('af1c0010000000000000000000000010.jpg', 'El Fantasma de la Ópera', 'Terror / Drama', 'Rupert Julian', 1925, 93, ((24, 14, 20), (65, 25, 45), (200, 155, 60), (245, 235, 240)), 'fantasma'),
    ('af1c0011000000000000000000000011.jpg', 'Viaje a lo Imposible', 'Aventura / Fantasía', 'Georges Méliès', 1904, 20, ((26, 18, 24), (70, 40, 60), (230, 175, 45), (250, 245, 230)), 'imposible'),
    ('af1c0012000000000000000000000012.jpg', 'El Pibe', 'Comedia / Drama', 'Charles Chaplin', 1921, 68, ((24, 22, 20), (60, 52, 45), (205, 160, 85), (245, 240, 230)), 'pibe'),
]

salas = [
    ('c1a1a001000000000000000000000001.jpg', 'Cine Club El Alambique', 'Luján', 'Espacio cultural de proyección y debate.', 'teatro'),
    ('c1a1a002000000000000000000000002.jpg', 'Espacio Cultural La Trocha', 'Mercedes', 'Antigua estación recuperada como sala comunitaria.', 'trocha'),
    ('c1a1a003000000000000000000000003.jpg', 'Cine Teatro Eureka', 'General Rodríguez', 'Sala cooperativa de alta definición.', 'eureka'),
    ('c1a1a004000000000000000000000004.jpg', 'Cine Club Areco', 'San Antonio de Areco', 'Microcine independiente en el casco histórico.', 'areco'),
]

if __name__ == '__main__':
    for p in posters:
        make_poster(*p)
    for s in salas:
        make_sala(*s)
    print('Imágenes generadas con éxito.')
