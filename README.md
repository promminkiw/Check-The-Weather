# Check-The-Weather
เว็บแอปตรวจสอบสภาพอากาศแบบเรียลไทม์ รองรับการเลือกจังหวัดทั่วประเทศไทย ใช้ตำแหน่ง GPS ได้ และสามารถสลับหน่วยอุณหภูมิระหว่าง °C / °F  
ออกแบบ UI แนว Modern Card ใช้งานง่าย และรองรับการแสดงผลบนมือถือ

---

##  Features
-  ตรวจสอบสภาพอากาศจากตำแหน่งปัจจุบัน (GPS)
-  เลือกจังหวัดในประเทศไทย (จัดกลุ่มตามภาค)
-  สลับหน่วยอุณหภูมิ Celsius / Fahrenheit
-  รีเฟรชข้อมูลอัตโนมัติทุก 60 วินาที
-  แสดงเวลาที่อัปเดตล่าสุด (relative time)
-  พยากรณ์อุณหภูมิรายชั่วโมง (แสดงเป็นไอคอน)
-  เปลี่ยนพื้นหลังตามสภาพอากาศ (กลางวัน / กลางคืน / ฝน / หมอก ฯลฯ)
-  Responsive รองรับมือถือและแท็บเล็ต

---

##  Tech Stack
**Frontend**
- HTML5  
- CSS3 (Responsive + Glassmorphism)  
- JavaScript (Vanilla JS)  
- Weather Icons (CDN)

**Backend**
- PHP (`weather.php`)
- Open-Meteo API

**Data**
- `regions.json` — ข้อมูลภาคของประเทศไทย  
- `provinces.json` — รายชื่อจังหวัดทั้งหมด  

---------------------------------------

```text
Check-The-Weather/
├── index.html # หน้าเว็บหลัก
├── weather.php # Backend สำหรับเรียก API สภาพอากาศ
├── regions.json # ข้อมูลภาค
├── provinces.json # ข้อมูลจังหวัด
├── bg/ # ภาพพื้นหลังตามสภาพอากาศ
│ ├── clear-day.jpg
│ ├── clear-night.jpg
│ ├── cloudy.jpg
│ ├── rain.jpg
│ ├── fog.jpg
│ ├── snow.jpg
│ └── thunder.jpg
└── README.md

---

## ▶️ วิธีใช้งาน (Run Project)

### วิธีที่ 1: ใช้ PHP Built-in Server (แนะนำ)
```bash
php -S localhost:8000

เปิด Browser:
http://localhost:8000/index.html


## API Reference
Weather API: https://open-meteo.com/
Weather Icons: https://erikflowers.github.io/weather-icons/
