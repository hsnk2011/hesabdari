(function(global) {
    // Jalaali Core Logic (Embedded to ensure no dependencies)
    const Jalaali = {
        toJalaali: function(gy, gm, gd) {
            var g_d_m, jy, jm, jd, gy2, days;
            g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
            gy2 = (gm > 2) ? (gy + 1) : gy;
            days = 355666 + (365 * gy) + ~~((gy2 + 3) / 4) - ~~((gy2 + 99) / 100) + ~~((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
            jy = -1595 + (33 * ~~(days / 12053));
            days %= 12053;
            jy += 4 * ~~(days / 1461);
            days %= 1461;
            if (days > 365) {
                jy += ~~((days - 1) / 365);
                days = (days - 1) % 365;
            }
            if (days < 186) {
                jm = 1 + ~~(days / 31);
                jd = 1 + (days % 31);
            } else {
                jm = 7 + ~~((days - 186) / 30);
                jd = 1 + ((days - 186) % 30);
            }
            return [jy, jm, jd];
        },
        toGregorian: function(jy, jm, jd) {
            var gy, gm, gd, days, sal_a, v;
            jy += 1595;
            days = -355668 + (365 * jy) + (~~(jy / 33) * 8) + ~~(((jy % 33) + 3) / 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
            gy = 400 * ~~(days / 146097);
            days %= 146097;
            if (days > 36524) {
                days--;
                gy += 100 * ~~(days / 36524);
                days %= 36524;
                if (days >= 365) days++;
            }
            gy += 4 * ~~(days / 1461);
            days %= 1461;
            if (days > 365) {
                gy += ~~((days - 1) / 365);
                days = (days - 1) % 365;
            }
            gd = days + 1;
            sal_a = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
            for (gm = 0; gm < 13; gm++) {
                v = sal_a[gm];
                if (gd <= v) break;
                gd -= v;
            }
            return [gy, gm, gd];
        },
        isValidJalaaliDate: function(jy, jm, jd) {
            return  jy >= -61 && jy <= 3177 &&
                    jm >= 1 && jm <= 12 &&
                    jd >= 1 && jd <= Jalaali.jalaaliMonthLength(jy, jm);
        },
        isLeapJalaaliYear: function(jy) {
            return Jalaali.jalCal(jy).leap === 0;
        },
        jalaaliMonthLength: function(jy, jm) {
            if (jm <= 6) return 31;
            if (jm <= 11) return 30;
            if (Jalaali.isLeapJalaaliYear(jy)) return 30;
            return 29;
        },
        jalCal: function(jy) {
            var breaks =  [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1212, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178]
            , bl = breaks.length
            , gy = jy + 621
            , leapJ = -14
            , jp = breaks[0]
            , jump
            , n
            , i
            , leap
            , my
            , k;
            if (jy < jp || jy >= breaks[bl - 1])
                throw new Error('Invalid Jalaali year ' + jy);
            for (i = 1; i < bl; i += 1) {
                my = breaks[i];
                jump = my - jp;
                if (jy < my) {
                    n = jy - jp;
                    leapJ = leapJ + ~~(n / 33) * 8 + ~~(((n % 33) + 3) / 4);
                    if ((jump % 33) === 4 && (jump - n) === 4)
                        leapJ += 1;
                    leap = (n + 1) % 33; // Note: this is not quite correct for all years but sufficient for leap check
                    // Better leap check:
                    var r = (n + 1) % 33;
                    var isLeap = (r%4 === 0 && r!==32); // Simplified
                    // Actually, let's use the standard algorithm for leap year
                    // This jalCal is complex, let's use a simpler one for isLeap
                    return { leap: ((((jy % 33) + 1) % 4) === 0) ? 0 : 1 }; // Fallback approximation
                }
                leapJ += ~~(jump / 33) * 8 + ~~(((jump % 33) + 3) / 4);
                jp = my;
            }
        }
    };
    
    // Override isLeap with a better one
    Jalaali.isLeapJalaaliYear = function(jy) {
        var r = (((jy - 474) % 2820) + 474 + 38) * 682;
        return (r % 2816) < 682;
    };

    class BDDatepicker {
        constructor(element, options = {}) {
            this.element = element;
            this.options = options;
            this.visible = false;
            this.view = 'day'; // day, month, year
            
            const now = new Date();
            const jNow = Jalaali.toJalaali(now.getFullYear(), now.getMonth() + 1, now.getDate());
            this.today = { y: jNow[0], m: jNow[1], d: jNow[2] };
            this.current = { ...this.today };
            this.selected = null;

            this.init();
        }

        init() {
            this.element.addEventListener('focus', () => this.show());
            this.element.addEventListener('click', (e) => {
                e.stopPropagation();
                this.show();
            });
            
            // Sync on input
            this.element.addEventListener('input', () => this.parseInput());
            this.element.addEventListener('change', () => this.parseInput());

            // Parse initial value
            this.parseInput();

            this.container = document.createElement('div');
            this.container.className = 'bd-datepicker-container';
            document.body.appendChild(this.container);

            this.container.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', () => this.hide());
        }

        parseInput() {
            if (!this.element.value) return;
            const parts = this.element.value.split('/');
            if (parts.length === 3) {
                const y = parseInt(parts[0]);
                const m = parseInt(parts[1]);
                const d = parseInt(parts[2]);
                if (!isNaN(y) && !isNaN(m) && !isNaN(d)) {
                    if (Jalaali.isValidJalaaliDate(y, m, d)) {
                        this.selected = { y, m, d };
                        this.current = { ...this.selected };
                        if (this.visible) this.render();
                    }
                }
            }
        }

        show() {
            if (this.visible) return;
            
            // Close other instances
            document.querySelectorAll('.bd-datepicker-container').forEach(el => el.style.display = 'none');
            
            const rect = this.element.getBoundingClientRect();
            this.container.style.top = (rect.bottom + window.scrollY + 5) + 'px';
            this.container.style.left = (rect.left + window.scrollX) + 'px'; // LTR default, but CSS is RTL
            // Adjust for RTL if needed or screen edge
            
            this.container.style.display = 'block';
            this.visible = true;
            this.render();
        }

        hide() {
            this.container.style.display = 'none';
            this.visible = false;
            this.view = 'day';
        }

        render() {
            this.container.innerHTML = '';
            
            const header = document.createElement('div');
            header.className = 'bd-datepicker-header';
            
            const prevBtn = document.createElement('button');
            prevBtn.innerHTML = '&#8249;';
            prevBtn.onclick = () => this.prev();
            
            const title = document.createElement('div');
            title.className = 'bd-datepicker-title';
            title.onclick = () => this.switchView();
            
            const nextBtn = document.createElement('button');
            nextBtn.innerHTML = '&#8250;';
            nextBtn.onclick = () => this.next();
            
            header.appendChild(nextBtn); // RTL: Next is Right
            header.appendChild(title);
            header.appendChild(prevBtn); // RTL: Prev is Left
            
            this.container.appendChild(header);

            if (this.view === 'day') {
                this.renderDayView(title);
            } else if (this.view === 'month') {
                this.renderMonthView(title);
            } else if (this.view === 'year') {
                this.renderYearView(title);
            }
            
            const footer = document.createElement('div');
            footer.className = 'bd-datepicker-footer';
            const todayBtn = document.createElement('button');
            todayBtn.className = 'bd-datepicker-today-btn';
            todayBtn.innerText = 'امروز';
            todayBtn.onclick = () => this.gotoToday();
            footer.appendChild(todayBtn);
            this.container.appendChild(footer);
        }

        renderDayView(titleElem) {
            const months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            titleElem.innerText = `${months[this.current.m - 1]} ${this.current.y}`;

            const weekdaysDiv = document.createElement('div');
            weekdaysDiv.className = 'bd-datepicker-weekdays';
            ['ش','ی','د','س','چ','پ','ج'].forEach(d => {
                const el = document.createElement('div');
                el.className = 'bd-datepicker-weekday';
                el.innerText = d;
                weekdaysDiv.appendChild(el);
            });
            this.container.appendChild(weekdaysDiv);

            const daysDiv = document.createElement('div');
            daysDiv.className = 'bd-datepicker-days';

            // Calculate start day of month
            // 1 Farvardin is usually around 21 March.
            // We convert 1st of current Jalali month to Gregorian to find day of week
            const gDate = Jalaali.toGregorian(this.current.y, this.current.m, 1);
            const dateObj = new Date(gDate[0], gDate[1] - 1, gDate[2]);
            let startDay = dateObj.getDay(); // 0=Sun, 1=Mon, ... 6=Sat
            // Map to Shanbe=0, Yekshanbe=1 ... Jome=6
            // JS: Sun=0, Mon=1, Tue=2, Wed=3, Thu=4, Fri=5, Sat=6
            // FA: Sat=0, Sun=1, Mon=2, Tue=3, Wed=4, Thu=5, Fri=6
            const map = {6:0, 0:1, 1:2, 2:3, 3:4, 4:5, 5:6};
            startDay = map[startDay];

            const monthLen = Jalaali.jalaaliMonthLength(this.current.y, this.current.m);

            for (let i = 0; i < startDay; i++) {
                const empty = document.createElement('div');
                empty.className = 'bd-datepicker-day empty';
                daysDiv.appendChild(empty);
            }

            for (let d = 1; d <= monthLen; d++) {
                const dayEl = document.createElement('div');
                dayEl.className = 'bd-datepicker-day';
                dayEl.innerText = d;
                
                if (this.current.y === this.today.y && this.current.m === this.today.m && d === this.today.d) {
                    dayEl.classList.add('today');
                }
                
                if (this.selected && this.selected.y === this.current.y && this.selected.m === this.current.m && this.selected.d === d) {
                    dayEl.classList.add('selected');
                }

                dayEl.onclick = () => this.selectDate(d);
                daysDiv.appendChild(dayEl);
            }

            this.container.appendChild(daysDiv);
        }

        renderMonthView(titleElem) {
            titleElem.innerText = `${this.current.y}`;
            const monthsDiv = document.createElement('div');
            monthsDiv.className = 'bd-datepicker-month-view';
            const months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            
            months.forEach((m, idx) => {
                const el = document.createElement('div');
                el.className = 'bd-datepicker-month-item';
                el.innerText = m;
                el.onclick = () => {
                    this.current.m = idx + 1;
                    this.view = 'day';
                    this.render();
                };
                monthsDiv.appendChild(el);
            });
            this.container.appendChild(monthsDiv);
        }

        renderYearView(titleElem) {
            const startYear = this.current.y - 6;
            const endYear = this.current.y + 5;
            titleElem.innerText = `${startYear} - ${endYear}`;
            
            const yearsDiv = document.createElement('div');
            yearsDiv.className = 'bd-datepicker-year-view';
            
            for (let y = startYear; y <= endYear; y++) {
                const el = document.createElement('div');
                el.className = 'bd-datepicker-year-item';
                el.innerText = y;
                el.onclick = () => {
                    this.current.y = y;
                    this.view = 'month';
                    this.render();
                };
                yearsDiv.appendChild(el);
            }
            this.container.appendChild(yearsDiv);
        }

        prev() {
            if (this.view === 'day') {
                this.current.m--;
                if (this.current.m < 1) {
                    this.current.m = 12;
                    this.current.y--;
                }
            } else if (this.view === 'month') {
                this.current.y--;
            } else if (this.view === 'year') {
                this.current.y -= 12;
            }
            this.render();
        }

        next() {
            if (this.view === 'day') {
                this.current.m++;
                if (this.current.m > 12) {
                    this.current.m = 1;
                    this.current.y++;
                }
            } else if (this.view === 'month') {
                this.current.y++;
            } else if (this.view === 'year') {
                this.current.y += 12;
            }
            this.render();
        }

        switchView() {
            if (this.view === 'day') this.view = 'month';
            else if (this.view === 'month') this.view = 'year';
            this.render();
        }

        gotoToday() {
            this.current = { ...this.today };
            this.selected = { ...this.today };
            this.view = 'day';
            
            const formatted = `${this.selected.y}/${String(this.selected.m).padStart(2, '0')}/${String(this.selected.d).padStart(2, '0')}`;
            this.element.value = formatted;
            this.element.dispatchEvent(new Event('change'));
            
            this.render();
            this.hide();
        }

        selectDate(d) {
            this.selected = { y: this.current.y, m: this.current.m, d: d };
            const formatted = `${this.selected.y}/${String(this.selected.m).padStart(2, '0')}/${String(this.selected.d).padStart(2, '0')}`;
            this.element.value = formatted;
            this.hide();
            // Trigger change event
            this.element.dispatchEvent(new Event('change'));
        }
    }

    global.BDDatepicker = BDDatepicker;

})(window);
