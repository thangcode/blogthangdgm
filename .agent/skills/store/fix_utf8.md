---
description: Quy trình bắt buộc để phát hiện, ngăn chặn, và sửa dứt điểm lỗi font tiếng Việt/UTF-8 trong dự án STORE
---

# FIX UTF-8 FOR STORE

Tài liệu này là hướng dẫn chuyên biệt để xử lý lỗi tiếng Việt bị vỡ font trong dự án STORE. Mục tiêu không phải sửa từng chữ một cách tạm thời, mà là chặn đúng nguyên nhân và áp dụng một quy trình ổn định để lỗi không tái phát.

Áp dụng cho toàn bộ file:
- `.php`
- `.js`
- `.css`
- `.sql`
- template HTML render ra giao diện
- file cache HTML nếu hệ thống có cơ chế page cache

## 1. Nhận diện đúng lỗi

### 1.1 Dấu hiệu thường gặp
Nếu thấy các chuỗi sau, đó là lỗi encoding:
- `ThÃ´ng tin`
- `Sáº£n pháº©m`
- `ÄÄƒng kÃ½`
- `chá»§`
- `Tá»‰nh`
- `GiÃ¡`
- `Ä‘`
- ký tự thay thế `�`

### 1.2 Bản chất kỹ thuật
Lỗi này xảy ra khi:
- text UTF-8 bị đọc như Windows-1252 / Latin-1
- file đã bị lưu sai encoding
- shell writer ghi lại file bằng encoding khác UTF-8
- trang đang render từ cache HTML cũ
- text đã đúng trong source nhưng browser đang thấy bản cũ đã cache

### 1.3 Hậu quả
- Giao diện nhìn như bị hỏng
- mất độ tin cậy với người dùng
- SEO xấu vì title/meta/breadcrumb bị vỡ
- dữ liệu quản trị khó kiểm soát vì không biết lỗi nằm ở source hay cache

## 2. Tư duy đúng khi sửa

### 2.1 Không sửa theo kiểu chắp vá
Không được chỉ thấy chữ nào vỡ thì thay chữ đó rồi dừng.

Luôn phải kiểm tra đủ 4 lớp:
1. Source file
2. Encoding của file
3. Cache HTML/PHP
4. Các template khác đang render cùng nội dung

### 2.2 Không tin vào “đã sửa source là xong”
Một file đã sửa đúng vẫn có thể hiển thị sai nếu:
- cache đang trả HTML cũ
- còn một file khác render cùng block
- shell trước đó đã ghi hỏng phần khác của file

### 2.3 Luôn ưu tiên giải pháp bền
Thứ tự ưu tiên:
1. Chuẩn hóa file về `UTF-8 without BOM`
2. Dùng text UTF-8 chuẩn nếu file sạch
3. Nếu file đang nhiễm encoding hoặc môi trường ghi file không ổn định, dùng:
   - HTML entity cho phần render HTML
   - Unicode escape cho string trong JS
4. Tắt cache tạm thời trong lúc sửa giao diện

## 3. Quy tắc bắt buộc khi thao tác

### 3.1 Encoding chuẩn
Tất cả file giao diện phải dùng:
- `UTF-8 (no BOM)`

Không dùng:
- ANSI
- Windows-1252
- UTF-8 with BOM nếu file hiện tại đang không dùng BOM

### 3.2 Cấm ghi file kiểu dễ làm vỡ tiếng Việt
Tránh các cách ghi file có nguy cơ đổi encoding:
- PowerShell `Set-Content` không chỉ rõ encoding
- `Out-File` không kiểm soát encoding
- copy/paste qua terminal làm biến dạng ký tự
- script bulk replace không kiểm tra encoding đầu ra

### 3.3 Khi nào dùng entity thay vì chữ Việt trực tiếp
Dùng HTML entity nếu:
- file đã từng bị mojibake
- shell/editor đang ghi sai UTF-8
- chuỗi là text UI cố định trong HTML/PHP

Ví dụ:
- `Thông tin giá` -> `Th&#244;ng tin gi&#225;`
- `Sản phẩm nổi bật` -> `S&#7843;n ph&#7849;m n&#7893;i b&#7853;t`
- `Đăng ký` -> `&#272;&#259;ng k&#253;`
- `đ` -> `&#273;`

### 3.4 Khi nào dùng Unicode escape trong JS
Dùng cho chuỗi JS động để tránh file JS/PHP bị lưu sai:

Ví dụ:
```js
const regionText = 'T\u1EC9nh kh\u00E1c';
priceEl.textContent = value + ' \u0111/th\u00E1ng';
```

## 4. Quy trình chuẩn khi sửa lỗi tiếng Việt

### Bước 1: Xác định đúng file render
Không đoán.

Phải kiểm tra:
- file page chính, ví dụ `product.php`
- include/template card liên quan, ví dụ `includes/blocks/...`
- layout chung như `header.php`, `footer.php`
- nơi sinh modal, breadcrumb, sidebar, related items

### Bước 2: Quét pattern lỗi
Dùng scan để tìm dấu hiệu mojibake:

```powershell
rg -n "Ã|Â|Ä|�|á»|áº|Æ°" path\to\file.php
```

Nếu sửa theo module:
```powershell
rg -n "Ã|Â|Ä|�|á»|áº|Æ°" .\product.php .\includes .\admin
```

### Bước 3: Phân loại chuỗi
Mỗi match cần phân loại:
- text UI cố định
- text DB render ra
- text JS
- comment/code note

### Bước 4: Sửa theo loại

#### a. Text UI cố định trong HTML/PHP
Đổi sang HTML entity.

Ví dụ:
```php
<h5>Th&#244;ng tin gi&#225;</h5>
<button>&#272;&#259;ng k&#253;</button>
```

#### b. Ký hiệu tiền tệ
Không để `đ` trực tiếp nếu file đang có vấn đề encoding.

Dùng:
```php
<?php echo number_format($price, 0, ',', '.'); ?>&#273;
```

#### c. Text JS
Dùng Unicode escape:
```js
'T\u1EC9nh kh\u00E1c'
'\u0111/th\u00E1ng'
```

#### d. Text từ DB
Nếu text lấy từ DB mà bị vỡ:
- kiểm tra charset kết nối DB
- kiểm tra collation bảng/cột
- kiểm tra dữ liệu đã bị hỏng ngay từ lúc insert hay chưa

Không được vội kết luận lỗi nằm ở source nếu DB mới là nơi đã lưu sai.

### Bước 5: Xử lý cache
Nếu project có page cache:
- tạm thời tắt cache ở trang đang sửa
- hoặc xóa cache trước khi kết luận “source chưa ăn”

Nguyên tắc:
- đang sửa giao diện mà còn bật cache HTML cũ thì rất dễ chẩn đoán sai

### Bước 6: Quét lại sau sửa
Quét lại chính file vừa sửa:

```powershell
rg -n "Ã|Â|Ä|�|á»|áº|Æ°" path\to\file.php
```

Sau đó quét theo module nếu block còn render sai:

```powershell
rg -n "Ã|Â|Ä|�|á»|áº|Æ°" .\includes .\product.php .\index.php
```

## 5. Quy trình đặc biệt cho dự án STORE

### 5.1 Các vùng có nguy cơ cao
Trong STORE, lỗi tiếng Việt thường xuất hiện ở:
- breadcrumb
- tiêu đề section
- sidebar giá
- sidebar sản phẩm nổi bật
- card sản phẩm liên quan
- modal đăng ký/tư vấn
- text JS đổi giá theo khu vực
- meta SEO
- nút CTA

### 5.2 Các file phải nghi ngờ đầu tiên
- `product.php`
- `index.php`
- `category.php`
- `includes/header.php`
- `includes/footer.php`
- `includes/blocks/*.php`
- file card tái sử dụng như `dynamic_card_product.php`

### 5.3 Với trang chi tiết sản phẩm
Phải kiểm tra đủ:
1. block nội dung sản phẩm
2. block liên hệ
3. block sản phẩm liên quan
4. sidebar giá
5. sidebar sản phẩm nổi bật
6. modal đăng ký
7. JS đổi giá theo vùng

Nếu chỉ sửa 1 khu vực rồi dừng thì gần như chắc chắn sẽ còn sót.

## 6. Khôi phục file đã hỏng nặng

### 6.1 Khi nào coi là file đã hỏng nặng
Khi file có nhiều chuỗi kiểu:
- `NhÃ¡ÂºÂ­p`
- `ÄÄƒng`
- `KhÃ¡ch hÃ ng`
- `Dá»‹ch vá»¥`

Lúc này không nên tiếp tục thêm chữ Việt trực tiếp vào file.

### 6.2 Cách xử lý an toàn
Ưu tiên:
1. đổi toàn bộ text UI cố định sang entity
2. dùng escape trong JS
3. chỉ chuẩn hóa encoding file khi chắc công cụ ghi file không làm sai thêm

### 6.3 Nếu cần phục hồi encoding
Chỉ dùng khi đã xác định file thực sự là UTF-8 bị đọc nhầm thành 1252.

Ví dụ PowerShell:
```powershell
[System.Text.Encoding]::UTF8.GetString(
    [System.Text.Encoding]::GetEncoding(1252).GetBytes(
        (Get-Content .\path\to\file.php -Raw)
    )
) | Set-Content .\path\to\file.php -Encoding UTF8
```

Cảnh báo:
- Lệnh này không phải lúc nào cũng đúng
- phải backup hoặc xác nhận ngữ cảnh trước khi dùng
- không áp dụng mù quáng cho mọi file

## 7. Checklist bắt buộc trước khi kết luận “đã xong”

### 7.1 Kiểm tra source
- không còn chuỗi mojibake trong file vừa sửa
- không còn `Ä‘` ở chỗ tiền tệ
- không còn `ThÃ´ng`, `GiÃ¡`, `Sáº£n`, `Tá»‰nh`

### 7.2 Kiểm tra cache
- cache trang đã tắt hoặc đã xóa
- không còn page cache giữ HTML cũ

### 7.3 Kiểm tra template liên quan
- block card liên quan
- sidebar item
- modal
- include dùng chung

### 7.4 Kiểm tra giao diện thật
Ít nhất phải nhìn lại:
- 1 trang chi tiết sản phẩm
- 1 block liên quan/sidebar
- 1 modal nếu trang đó có

## 8. Anti-pattern cần tránh

Không được:
- sửa xong một chữ rồi kết luận toàn trang đã ổn
- đổ lỗi ngay cho browser cache khi chưa kiểm tra source
- chèn thêm text tiếng Việt trực tiếp vào file đang nhiễm encoding nặng
- dùng shell write không kiểm soát encoding
- quên kiểm tra JS string
- quên file include render cùng block

## 9. Quy tắc phản ứng khi user báo “vẫn lỗi”

Khi user nói vẫn còn lỗi, agent phải làm theo đúng thứ tự:
1. không tranh luận
2. đọc lại đúng file render hiện tại
3. quét pattern mojibake
4. kiểm tra cache
5. kiểm tra file include/template phụ
6. sửa dứt điểm theo entity/escape

Không được trả lời kiểu:
- “chắc là cache”
- “mình đã sửa rồi”
- “có thể do trình duyệt”

khi chưa đối chiếu source thực tế.

## 10. Mẫu thay thế an toàn thường dùng

### HTML/PHP
```php
Trang ch&#7911;
Th&#244;ng tin gi&#225;
Gi&#225; s&#7843;n ph&#7849;m
S&#7843;n ph&#7849;m li&#234;n quan
S&#7843;n ph&#7849;m n&#7893;i b&#7853;t
C&#249;ng danh m&#7909;c
Xem t&#7845;t c&#7843;
&#272;&#259;ng k&#253;
&#272;&#7883;a ch&#7881;
&#273;
```

### JavaScript
```js
const regionText = 'T\u1EC9nh kh\u00E1c';
priceEl.textContent = new Intl.NumberFormat('vi-VN').format(value) + ' \u0111/th\u00E1ng';
```

## 11. Chính sách bắt buộc cho mọi agent trong STORE

Nếu tác vụ có liên quan đến tiếng Việt:
- luôn giả định có rủi ro encoding cho đến khi kiểm tra xong
- luôn quét mojibake sau khi sửa
- luôn nghi ngờ cache nếu giao diện không khớp source
- luôn ưu tiên entity/escape trong file đã từng lỗi

Nếu agent gây ra lỗi tiếng Việt:
- phải sửa tận gốc
- không được bỏ lại file ở trạng thái “đã sửa một phần”
- phải mở rộng kiểm tra sang các block liên quan cùng luồng render

## 12. Kết luận

Lỗi tiếng Việt trong STORE không phải lỗi “gõ sai chữ”, mà là lỗi hệ thống giữa:
- encoding file
- cách ghi file
- cache render
- template dùng chung

Muốn sửa dứt điểm, phải xử lý như một vấn đề kiến trúc nhỏ, không phải vá từng label.

Agent nào làm việc trong dự án này phải coi UTF-8 là guardrail bắt buộc, không phải chi tiết phụ.
