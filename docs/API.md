# KrishiHat REST API (v1)

Base URL (local): `http://127.0.0.1:8000/api/v1`

সব রেসপন্স JSON। লগইন করা দরকার এমন এন্ডপয়েন্টে হেডার দিন:
`Authorization: Bearer <token>`

| Method | URL | কে ব্যবহার করবে | কাজ |
|---|---|---|---|
| GET | `/products` | সবাই | পণ্য তালিকা। ফিল্টার: `q`, `category` (id বা slug), `district` (id বা নাম), `min_price`, `max_price`, `in_stock=1`, `sort` (newest, price_asc, price_desc, name), `per_page` (১-৫০) |
| GET | `/products/{id}` | সবাই | একটা পণ্যের বিবরণ |
| GET | `/categories` | সবাই | ক্যাটাগরি তালিকা |
| GET | `/market-prices` | সবাই | বাজারদর। ফিল্টার: `crop`, `district`, `price_type`, `verified=1` |
| POST | `/auth/register` | সবাই | নতুন অ্যাকাউন্ট (`name`, `email`, `password`, `password_confirmation`, `role`=farmer/buyer) |
| POST | `/auth/login` | সবাই | লগইন, token পাবেন |
| POST | `/auth/logout` | লগইন করা | টোকেন বাতিল |
| GET | `/me` | লগইন করা | নিজের তথ্য |
| GET | `/orders`, `/orders/{id}` | লগইন করা | ক্রেতা: নিজের অর্ডার; কৃষক: নিজের পণ্যের অর্ডার; অ্যাডমিন: সব |
| GET | `/offers` | লগইন করা | ক্রেতা: দেওয়া অফার; কৃষক: পাওয়া অফার |
| GET | `/farmer/products` | কৃষক | নিজের পণ্য |
| POST | `/farmer/products` | ভেরিফায়েড কৃষক | নতুন পণ্য |
| PUT/PATCH | `/farmer/products/{id}` | পণ্যের মালিক কৃষক | পণ্য আপডেট |
| DELETE | `/farmer/products/{id}` | পণ্যের মালিক কৃষক | পণ্য মোছা (অর্ডার/অফার থাকলে নয়) |

## সুরক্ষা
- টোকেন ডাটাবেসে SHA-256 হ্যাশ করে রাখা হয়। নতুন লগইন করলে আগের টোকেন বাতিল হয়।
- সাধারণ রেট লিমিট: প্রতি মিনিটে ৬০ রিকোয়েস্ট। লগইন/রেজিস্টার: প্রতি মিনিটে ৫ বার (ইমেইল + IP অনুযায়ী)। ওয়েবসাইটের লগইন ফর্ম: প্রতি মিনিটে ১০ বার।
- `admin` রোল API দিয়ে নেওয়া যায় না।

## উদাহরণ (PowerShell)
```
curl.exe "http://127.0.0.1:8000/api/v1/products?q=আলু&sort=price_asc"
curl.exe -X POST http://127.0.0.1:8000/api/v1/auth/login -H "Content-Type: application/json" -d "{\"email\":\"admin@example.com\",\"password\":\"12345678\"}"
```
